<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.three_rings', [
            'base_url' => 'https://www.3r.org.uk',
            'key' => 'test-key',
            'contact_email' => 'dev@bloodbikes.wales',
            'rate_limit' => ['max_attempts' => 15, 'decay_seconds' => 60],
            'cache' => [
                'volunteers' => ['fresh' => 3600, 'stale' => 86400],
                'roles' => ['fresh' => 21600, 'stale' => 172800],
                'shifts' => ['fresh' => 300, 'stale' => 3600],
            ],
        ]);

        Http::preventStrayRequests();
    }

    public function test_me_returns_mapped_three_rings_roles(): void
    {
        $this->fakeDirectory([
            [
                'id' => 100001,
                'name' => 'Alex Morgan',
                'roles' => [
                    ['role' => ['id' => 5001, 'name' => 'Rider']],
                    ['role' => ['id' => 5002, 'name' => 'Controller']],
                    ['role' => ['id' => 5003, 'name' => 'Trustees']],
                    ['role' => ['id' => 9999, 'name' => 'Everyone']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
                ],
            ],
        ]);
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('email', 'rider@bloodbikes.wales')
            ->assertJsonMissingPath('is_admin')
            ->assertJsonPath('roles', ['controller', 'rider', 'trustee']);
    }

    public function test_me_includes_admin_role_when_user_is_admin(): void
    {
        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        $this->fakeDirectory([
            [
                'id' => 100001,
                'name' => 'Test Rider',
                'roles' => [
                    ['role' => ['id' => 5002, 'name' => 'Controller']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
                ],
            ],
        ]);
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk()
            ->assertJsonMissingPath('is_admin')
            ->assertJsonPath('roles', ['admin', 'controller']);
    }

    public function test_me_returns_empty_roles_when_email_not_in_directory(): void
    {
        $this->fakeDirectory([
            [
                'id' => 100002,
                'name' => 'Someone Else',
                'roles' => [
                    ['role' => ['id' => 5003, 'name' => 'Driver']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'other@bloodbikes.wales']],
                ],
            ],
        ]);
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('roles', []);
    }

    public function test_me_succeeds_with_empty_three_rings_roles_when_directory_unavailable(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response('unavailable', 503),
        ]);
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('email', 'rider@bloodbikes.wales')
            ->assertJsonPath('roles', []);
    }

    public function test_me_serves_roles_from_stale_directory_cache_during_outage(): void
    {
        $this->fakeDirectory([
            [
                'id' => 100001,
                'name' => 'Test Rider',
                'roles' => [
                    ['role' => ['id' => 5002, 'name' => 'Controller']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
                ],
            ],
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/me')->assertOk();

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response('unavailable', 503),
        ]);

        // Expire fresh cache so the next resolve must fall back to stale.
        $this->travel(2)->hours();

        $this->mockVerifiedClaims();
        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('roles', ['controller']);
    }

    /**
     * @param  list<array<string, mixed>>  $volunteers
     */
    private function fakeDirectory(array $volunteers): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => $volunteers]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mockVerifiedClaims(array $overrides = []): void
    {
        $claims = array_filter(array_merge([
            'sub' => 'google-sub-123',
            'email' => 'rider@bloodbikes.wales',
            'email_verified' => true,
            'hd' => 'bloodbikes.wales',
            'name' => 'Test Rider',
        ], $overrides), fn ($value) => $value !== null);

        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
