<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationalShiftTest extends TestCase
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
        $this->getJson('/api/shifts/active')->assertUnauthorized();
        $this->postJson('/api/shifts/logon', [])->assertUnauthorized();
    }

    public function test_any_authenticated_user_can_list_active_shifts(): void
    {
        $bike = Bike::factory()->create([
            'registration' => 'CF34 DEF',
            'last_recorded_mileage' => 9820,
        ]);
        OperationalShift::factory()->create([
            'rider_id' => 100002,
            'rider_name' => 'Mike Davies',
            'bike_id' => $bike->id,
            'start_mileage' => 9820,
        ]);

        $this->fakeDirectory($this->riderVolunteer());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/shifts/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.riderId', '100002')
            ->assertJsonPath('data.0.riderName', 'Mike Davies')
            ->assertJsonPath('data.0.bikeId', $bike->id)
            ->assertJsonPath('data.0.bikeRegistration', 'CF34 DEF')
            ->assertJsonPath('data.0.startMileage', 9820)
            ->assertJsonMissingPath('data.0.endMileage');
    }

    public function test_logged_off_shifts_are_omitted_from_the_active_list(): void
    {
        OperationalShift::factory()->loggedOff()->create();

        $this->fakeDirectory($this->controllerVolunteer());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/shifts/active')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_rider_cannot_log_on_or_off(): void
    {
        $bike = Bike::factory()->create();
        $shift = OperationalShift::factory()->create(['bike_id' => $bike->id]);

        $this->fakeDirectory($this->riderVolunteer());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => $bike->last_recorded_mileage,
            ])
            ->assertForbidden();

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [
                'endMileage' => $shift->start_mileage + 10,
            ])
            ->assertForbidden();
    }

    public function test_controller_can_log_a_rider_on(): void
    {
        $bike = Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'last_recorded_mileage' => 15234,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->postJson('/api/shifts/logon', [
            'riderId' => '100001',
            'riderName' => 'Alex Morgan',
            'bikeId' => $bike->id,
            'bikeRegistration' => 'CF12 ABC',
            'startMileage' => 15234,
        ]);

        $response->assertCreated()
            ->assertJsonPath('riderId', '100001')
            ->assertJsonPath('riderName', 'Alex Morgan')
            ->assertJsonPath('bikeId', $bike->id)
            ->assertJsonPath('bikeRegistration', 'CF12 ABC')
            ->assertJsonPath('startMileage', 15234)
            ->assertJsonPath('mileageVarianceReason', null)
            ->assertJsonMissingPath('data');

        $this->assertDatabaseHas('operational_shifts', [
            'rider_id' => 100001,
            'bike_id' => $bike->id,
            'start_mileage' => 15234,
            'logged_off_at' => null,
        ]);
    }

    public function test_admin_can_log_a_rider_on_without_a_controller_role(): void
    {
        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        $bike = Bike::factory()->create(['last_recorded_mileage' => 1000]);

        $this->fakeDirectory([$this->riderVolunteer()]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => 100001,
                'bikeId' => $bike->id,
                'startMileage' => 1000,
            ])
            ->assertCreated();
    }

    public function test_logon_requires_a_reason_when_start_mileage_differs(): void
    {
        $bike = Bike::factory()->create(['last_recorded_mileage' => 15234]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => 15000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mileageVarianceReason']);
    }

    public function test_logon_accepts_a_mileage_variance_reason(): void
    {
        $bike = Bike::factory()->create(['last_recorded_mileage' => 15234]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => 15000,
                'mileageVarianceReason' => 'Odometer reset after a repair',
            ])
            ->assertCreated()
            ->assertJsonPath('startMileage', 15000)
            ->assertJsonPath('mileageVarianceReason', 'Odometer reset after a repair');
    }

    public function test_a_rider_cannot_hold_two_active_shifts(): void
    {
        $firstBike = Bike::factory()->create();
        $secondBike = Bike::factory()->create();
        OperationalShift::factory()->create([
            'rider_id' => 100001,
            'rider_name' => 'Alex Morgan',
            'bike_id' => $firstBike->id,
            'start_mileage' => $firstBike->last_recorded_mileage,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $secondBike->id,
                'startMileage' => $secondBike->last_recorded_mileage,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['riderId']);
    }

    public function test_a_bike_cannot_hold_two_active_shifts(): void
    {
        $bike = Bike::factory()->create();
        OperationalShift::factory()->create([
            'rider_id' => 100003,
            'bike_id' => $bike->id,
            'start_mileage' => $bike->last_recorded_mileage,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => $bike->last_recorded_mileage,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);
    }

    public function test_logon_rejects_an_unknown_volunteer(): void
    {
        $bike = Bike::factory()->create();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '999999',
                'bikeId' => $bike->id,
                'startMileage' => $bike->last_recorded_mileage,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['riderId']);
    }

    public function test_controller_can_log_a_rider_off_and_updates_bike_mileage(): void
    {
        $bike = Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'last_recorded_mileage' => 15234,
        ]);
        $shift = OperationalShift::factory()->create([
            'rider_id' => 100001,
            'rider_name' => 'Alex Morgan',
            'bike_id' => $bike->id,
            'start_mileage' => 15234,
            'mileage_variance_reason' => null,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [
                'endMileage' => 15298,
                'faults' => 'Rear brake feels soft',
            ])
            ->assertOk()
            ->assertJsonPath('id', $shift->id)
            ->assertJsonPath('riderId', '100001')
            ->assertJsonPath('bikeRegistration', 'CF12 ABC');

        $this->assertNotNull($shift->fresh()->logged_off_at);
        $this->assertSame(15298, $bike->fresh()->last_recorded_mileage);
        $this->assertDatabaseHas('mileage_readings', [
            'bike_id' => $bike->id,
            'operational_shift_id' => $shift->id,
            'mileage' => 15298,
        ]);

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->getJson('/api/shifts/active')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_logoff_rejects_end_mileage_below_start_mileage(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 15234]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [
                'endMileage' => 15000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endMileage']);
    }

    public function test_logoff_rejects_an_already_logged_off_shift(): void
    {
        $shift = OperationalShift::factory()->loggedOff()->create();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [
                'endMileage' => $shift->end_mileage,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shift']);
    }

    public function test_logon_returns_503_when_the_directory_is_unavailable(): void
    {
        $bike = Bike::factory()->create();

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response('unavailable', 503),
        ]);
        $this->mockVerifiedClaims();

        // Admin so role middleware still allows the write when 3R roles cannot be resolved.
        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => $bike->last_recorded_mileage,
            ])
            ->assertStatus(503);
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
            'name' => 'Test Rider',
        ], $overrides), fn ($value) => $value !== null);

        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
