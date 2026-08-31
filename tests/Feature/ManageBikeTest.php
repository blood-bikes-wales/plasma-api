<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\BikeStatus;
use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManageBikeTest extends TestCase
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

    public function test_unauthenticated_requests_are_denied(): void
    {
        $bike = Bike::factory()->create();

        $this->postJson('/api/bikes', [])->assertUnauthorized();
        $this->patchJson("/api/bikes/{$bike->id}", [])->assertUnauthorized();
        $this->postJson("/api/bikes/{$bike->id}/retire")->assertUnauthorized();
    }

    public function test_a_controller_cannot_manage_bikes(): void
    {
        $bike = Bike::factory()->create();

        $this->fakeDirectory($this->controllerVolunteer());
        $this->mockVerifiedClaims(['email' => 'rider@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson('/api/bikes', $this->validCreatePayload())
            ->assertForbidden();

        $this->withToken('valid-token')
            ->patchJson("/api/bikes/{$bike->id}", $this->validUpdatePayload())
            ->assertForbidden();

        $this->withToken('valid-token')
            ->postJson("/api/bikes/{$bike->id}/retire")
            ->assertForbidden();
    }

    public function test_a_rider_cannot_manage_bikes(): void
    {
        $this->fakeDirectory($this->riderVolunteer());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/bikes', $this->validCreatePayload())
            ->assertForbidden();
    }

    public function test_a_trustee_creates_a_bike(): void
    {
        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $response = $this->withToken('valid-token')
            ->postJson('/api/bikes', $this->validCreatePayload());

        $response->assertCreated()
            ->assertJsonPath('registration', 'CF12 ABC')
            ->assertJsonPath('area', 'South')
            ->assertJsonPath('status', BikeStatus::Active->value)
            ->assertJsonPath('lastRecordedMileage', 12000)
            ->assertJsonPath('purchasedAt', '2024-03-15');

        $this->assertDatabaseHas('bikes', [
            'registration' => 'CF12 ABC',
            'area' => 'South',
            'status' => BikeStatus::Active->value,
            'last_recorded_mileage' => 12000,
        ]);
    }

    public function test_an_admin_creates_a_bike(): void
    {
        User::factory()->admin()->create([
            'email' => 'trustee@bloodbikes.wales',
            'google_id' => 'google-sub-123',
        ]);

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson('/api/bikes', $this->validCreatePayload())
            ->assertCreated()
            ->assertJsonPath('registration', 'CF12 ABC');
    }

    public function test_create_rejects_missing_and_invalid_fields(): void
    {
        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson('/api/bikes', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration', 'area', 'lastRecordedMileage']);

        $this->withToken('valid-token')
            ->postJson('/api/bikes', array_merge($this->validCreatePayload(), [
                'area' => 'East',
                'lastRecordedMileage' => -1,
                'purchasedAt' => now()->addDay()->toDateString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['area', 'lastRecordedMileage', 'purchasedAt']);
    }

    public function test_create_rejects_duplicate_registration(): void
    {
        Bike::factory()->create(['registration' => 'CF12 ABC']);

        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson('/api/bikes', $this->validCreatePayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration']);
    }

    public function test_a_trustee_updates_a_bike(): void
    {
        $bike = Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'area' => 'South',
        ]);

        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->patchJson("/api/bikes/{$bike->id}", [
                'registration' => 'cf34 def',
                'area' => 'North',
                'purchasedAt' => '2025-01-10',
            ])
            ->assertOk()
            ->assertJsonPath('registration', 'CF34 DEF')
            ->assertJsonPath('area', 'North')
            ->assertJsonPath('purchasedAt', '2025-01-10');

        $this->assertDatabaseHas('bikes', [
            'id' => $bike->id,
            'registration' => 'CF34 DEF',
            'area' => 'North',
        ]);
    }

    public function test_update_rejects_retired_bikes(): void
    {
        $bike = Bike::factory()->retired()->create();

        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->patchJson("/api/bikes/{$bike->id}", $this->validUpdatePayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bike']);
    }

    public function test_a_trustee_retires_a_bike(): void
    {
        $bike = Bike::factory()->create(['registration' => 'CF12 ABC']);

        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson("/api/bikes/{$bike->id}/retire")
            ->assertOk()
            ->assertJsonPath('status', BikeStatus::Retired->value);

        $bike->refresh();
        $this->assertSame(BikeStatus::Retired->value, $bike->status);
        $this->assertNotNull($bike->retired_at);
    }

    public function test_retire_rejects_bikes_on_an_active_shift(): void
    {
        $bike = Bike::factory()->create();
        OperationalShift::factory()->create(['bike_id' => $bike->id]);

        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson("/api/bikes/{$bike->id}/retire")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bike']);
    }

    public function test_retire_rejects_already_retired_bikes(): void
    {
        $bike = Bike::factory()->retired()->create();

        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson("/api/bikes/{$bike->id}/retire")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bike']);
    }

    public function test_managed_list_requires_manage_bikes_capability(): void
    {
        Bike::factory()->create(['registration' => 'CF12 ABC']);
        Bike::factory()->retired()->create(['registration' => 'CF34 DEF']);

        $this->fakeDirectory($this->controllerVolunteer());
        $this->mockVerifiedClaims(['email' => 'rider@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->getJson('/api/bikes?status=all')
            ->assertForbidden();
    }

    public function test_trustee_can_list_all_bikes_with_filters(): void
    {
        Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'area' => 'South',
        ]);
        Bike::factory()->retired()->create([
            'registration' => 'CF34 DEF',
            'area' => 'North',
        ]);

        $this->fakeDirectory($this->trusteeVolunteer());
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->getJson('/api/bikes?status=retired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.registration', 'CF34 DEF')
            ->assertJsonPath('data.0.status', BikeStatus::Retired->value);

        $this->withToken('valid-token')
            ->getJson('/api/bikes?area=South')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.registration', 'CF12 ABC');
    }

    public function test_controller_list_omits_retired_bikes(): void
    {
        Bike::factory()->create(['registration' => 'CF12 ABC']);
        Bike::factory()->retired()->create(['registration' => 'CF34 DEF']);

        $this->fakeDirectory($this->controllerVolunteer());
        $this->mockVerifiedClaims(['email' => 'rider@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->getJson('/api/bikes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.registration', 'CF12 ABC');
    }

    public function test_logon_rejects_a_retired_bike(): void
    {
        $bike = Bike::factory()->retired()->create([
            'last_recorded_mileage' => 5000,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => 5000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validCreatePayload(): array
    {
        return [
            'registration' => 'cf12 abc',
            'area' => 'South',
            'lastRecordedMileage' => 12000,
            'purchasedAt' => '2024-03-15',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validUpdatePayload(): array
    {
        return [
            'registration' => 'CF12 ABC',
            'area' => 'South',
            'purchasedAt' => '2024-03-15',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trusteeVolunteer(): array
    {
        return [
            'id' => 100020,
            'name' => 'Test Trustee',
            'roles' => [
                ['role' => ['id' => 5003, 'name' => 'Trustees']],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'trustee@bloodbikes.wales']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controllerVolunteer(): array
    {
        return [
            'id' => 100010,
            'name' => 'Test Controller',
            'roles' => [
                ['role' => ['id' => 5002, 'name' => 'Controller']],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function riderVolunteer(): array
    {
        return [
            'id' => 100001,
            'name' => 'Alex Morgan',
            'roles' => [
                ['role' => ['id' => 5001, 'name' => 'Rider']],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function controllerAndRiderVolunteers(): array
    {
        $rider = $this->riderVolunteer();
        $rider['volunteer_properties'] = [
            ['volunteer_property' => ['name' => 'Email', 'value' => 'alex@bloodbikes.wales']],
        ];

        return [$this->controllerVolunteer(), $rider];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $volunteers
     */
    private function fakeDirectory(array $volunteers): void
    {
        $list = array_is_list($volunteers) ? $volunteers : [$volunteers];

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
            'name' => 'Test User',
        ], $overrides), fn ($value) => $value !== null);

        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
