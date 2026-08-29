<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VolunteerIndexTest extends TestCase
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

    public function test_lists_volunteers_by_name_with_string_ids(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response([
                'volunteers' => [
                    [
                        'id' => 100002,
                        'name' => 'Mike Davies',
                        'roles' => [['role' => ['id' => 5001, 'name' => 'Rider']]],
                        'volunteer_properties' => [
                            ['volunteer_property' => ['name' => 'Email', 'value' => 'mike@bloodbikes.wales']],
                        ],
                    ],
                    [
                        'id' => 100001,
                        'name' => 'Alex Morgan',
                        'roles' => [['role' => ['id' => 5002, 'name' => 'Controller']]],
                        'volunteer_properties' => [
                            ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
                        ],
                    ],
                ],
            ]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/volunteers')
            ->assertOk()
            ->assertJsonPath('data.0.id', '100001')
            ->assertJsonPath('data.0.name', 'Alex Morgan')
            ->assertJsonPath('data.1.id', '100002')
            ->assertJsonPath('data.1.name', 'Mike Davies');
    }

    public function test_returns_503_when_the_directory_is_unavailable(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response('unavailable', 503),
        ]);
        $this->mockVerifiedClaims();

        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        $this->withToken('valid-token')
            ->getJson('/api/volunteers')
            ->assertStatus(503);
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
