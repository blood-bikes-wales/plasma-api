<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Bike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BikeIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_bikes_in_registration_order(): void
    {
        Bike::factory()->create([
            'registration' => 'CF34 DEF',
            'last_recorded_mileage' => 9820,
        ]);
        Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'last_recorded_mileage' => 15234,
        ]);

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/bikes')
            ->assertOk()
            ->assertJsonPath('data.0.registration', 'CF12 ABC')
            ->assertJsonPath('data.0.lastRecordedMileage', 15234)
            ->assertJsonPath('data.1.registration', 'CF34 DEF');
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/bikes')->assertUnauthorized();
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
