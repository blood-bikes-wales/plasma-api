<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Bike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
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

    public function test_signed_in_users_with_no_roles_can_read_me_but_nothing_else(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/me')->assertOk();
        $this->withToken('valid-token')->getJson('/api/shifts/active')->assertForbidden();
        $this->withToken('valid-token')->getJson('/api/bikes')->assertForbidden();
        $this->withToken('valid-token')->getJson('/api/volunteers')->assertForbidden();
        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [])
            ->assertForbidden();
        $this->withToken('valid-token')
            ->postJson('/api/jobs', [])
            ->assertForbidden();
    }

    public function test_a_rider_can_read_active_shifts_but_not_manage_them(): void
    {
        $this->fakeDirectory($this->volunteer('Rider'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/shifts/active')->assertOk();
        $this->withToken('valid-token')->getJson('/api/bikes')->assertForbidden();
        $this->withToken('valid-token')->getJson('/api/volunteers')->assertForbidden();
        $this->withToken('valid-token')->postJson('/api/jobs', [])->assertForbidden();
    }

    public function test_a_client_active_role_header_does_not_grant_access(): void
    {
        $bike = Bike::factory()->create();

        $this->fakeDirectory($this->volunteer('Rider'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->withHeader('X-Active-Role', 'controller')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => $bike->last_recorded_mileage,
            ])
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function volunteer(string $roleName): array
    {
        return [
            'id' => 100001,
            'name' => 'Alex Morgan',
            'roles' => [
                ['role' => ['id' => 5001, 'name' => $roleName]],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $volunteers
     */
    private function fakeDirectory(array $volunteers): void
    {
        $list = [$volunteers];
        if (array_is_list($volunteers)) {
            $list = $volunteers;
        }

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => $list]),
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
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
