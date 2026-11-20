<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\Rider;
use App\Models\ServiceArea;
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

        ServiceArea::query()->create([
            'code' => 'E',
            'full_name' => 'East',
            'three_rings_role' => 'Rider - East',
        ]);
        ServiceArea::query()->create([
            'code' => 'C',
            'full_name' => 'Carms',
            'three_rings_role' => 'Rider - Carms',
        ]);
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
            'initial_mileage' => 9820,
        ]);
        $rider = Rider::factory()->create(['external_id' => 100002, 'name' => 'Mike Davies']);
        OperationalShift::factory()->create([
            'rider_id' => $rider->id,
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
                'startMileage' => $bike->currentMileage(),
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
            'initial_mileage' => 15234,
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

        $this->assertDatabaseHas('riders', [
            'external_id' => 100001,
            'name' => 'Alex Morgan',
        ]);
        $this->assertDatabaseHas('operational_shifts', [
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

        $bike = Bike::factory()->create(['initial_mileage' => 1000]);

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
        $bike = Bike::factory()->create(['initial_mileage' => 15234]);

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
        $bike = Bike::factory()->create(['initial_mileage' => 15234]);

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
        $rider = Rider::factory()->create(['external_id' => 100001, 'name' => 'Alex Morgan']);
        OperationalShift::factory()->create([
            'rider_id' => $rider->id,
            'bike_id' => $firstBike->id,
            'start_mileage' => $firstBike->currentMileage(),
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $secondBike->id,
                'startMileage' => $secondBike->currentMileage(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['riderId']);
    }

    public function test_a_bike_cannot_hold_two_active_shifts(): void
    {
        $bike = Bike::factory()->create();
        $rider = Rider::factory()->create(['external_id' => 100003, 'name' => 'Other Rider']);
        OperationalShift::factory()->create([
            'rider_id' => $rider->id,
            'bike_id' => $bike->id,
            'start_mileage' => $bike->currentMileage(),
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => $bike->currentMileage(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);
    }

    public function test_logon_accepts_a_bike_from_any_area_a_multi_area_rider_covers(): void
    {
        $carmsBike = $this->bikeInArea('C', ['initial_mileage' => 4000]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers($this->riderVolunteer(['Rider - East', 'Rider - Carms'])));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $carmsBike->id,
                'startMileage' => 4000,
            ])
            ->assertCreated()
            ->assertJsonPath('bikeId', $carmsBike->id);
    }

    public function test_logon_rejects_a_bike_from_an_area_the_rider_does_not_cover(): void
    {
        $carmsBike = $this->bikeInArea('C', ['initial_mileage' => 4000]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $carmsBike->id,
                'startMileage' => 4000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);

        $this->assertDatabaseCount('operational_shifts', 0);
    }

    public function test_logon_rejects_a_rider_with_no_area_role(): void
    {
        $bike = Bike::factory()->create(['initial_mileage' => 1000]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers($this->riderVolunteer(['Rider - Milk'])));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', [
                'riderId' => '100001',
                'bikeId' => $bike->id,
                'startMileage' => 1000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);

        $this->assertDatabaseCount('operational_shifts', 0);
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
                'startMileage' => $bike->currentMileage(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['riderId']);
    }

    public function test_controller_can_log_a_rider_off_and_updates_bike_mileage(): void
    {
        $bike = Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'initial_mileage' => 15234,
        ]);
        $rider = Rider::factory()->create(['external_id' => 100001, 'name' => 'Alex Morgan']);
        $shift = OperationalShift::factory()->create([
            'rider_id' => $rider->id,
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
        $this->assertSame(15298, $bike->fresh()->currentMileage());
        $this->assertDatabaseHas('mileage_readings', [
            'operational_shift_id' => $shift->id,
            'mileage' => 15298,
        ]);

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->getJson('/api/shifts/active')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_logoff_without_bike_kept_at_stores_lockup(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 100]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 150])
            ->assertOk();

        $this->assertDatabaseHas('operational_shifts', [
            'id' => $shift->id,
            'bike_kept_at' => 'lockup',
        ]);
    }

    public function test_logoff_treats_a_blank_bike_kept_at_as_lockup(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 100]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 150, 'bikeKeptAt' => ''])
            ->assertOk();

        $this->assertDatabaseHas('operational_shifts', [
            'id' => $shift->id,
            'bike_kept_at' => 'lockup',
        ]);
    }

    public function test_logoff_stores_home_when_the_bike_is_kept_at_home(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 100]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 150, 'bikeKeptAt' => 'home'])
            ->assertOk();

        $this->assertDatabaseHas('operational_shifts', [
            'id' => $shift->id,
            'bike_kept_at' => 'home',
        ]);
    }

    public function test_logoff_stores_lockup_when_explicitly_chosen(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 100]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 150, 'bikeKeptAt' => 'lockup'])
            ->assertOk();

        $this->assertDatabaseHas('operational_shifts', [
            'id' => $shift->id,
            'bike_kept_at' => 'lockup',
        ]);
    }

    public function test_logoff_rejects_an_unknown_bike_kept_at_value(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 100]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 150, 'bikeKeptAt' => 'garage'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeKeptAt']);

        $this->assertNull($shift->fresh()->logged_off_at);
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

    public function test_logoff_accepts_a_mileage_change_just_under_the_threshold_without_an_explanation(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 15000]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 15499])
            ->assertOk();

        $this->assertDatabaseHas('operational_shifts', [
            'id' => $shift->id,
            'end_mileage' => 15499,
            'end_mileage_reason' => null,
        ]);
    }

    public function test_logoff_requires_an_explanation_for_a_mileage_change_of_500(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 15000]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 15500])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mileageExplanation']);

        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 15500, 'mileageExplanation' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mileageExplanation']);

        $this->assertNull($shift->fresh()->logged_off_at);
    }

    public function test_logoff_stores_the_explanation_for_a_mileage_change_of_500(): void
    {
        $shift = OperationalShift::factory()->create([
            'start_mileage' => 15000,
            'mileage_variance_reason' => 'Logon reason',
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [
                'endMileage' => 15500,
                'mileageExplanation' => '  Long distance transfer to Aberdeen  ',
            ])
            ->assertOk();

        $this->assertDatabaseHas('operational_shifts', [
            'id' => $shift->id,
            'end_mileage' => 15500,
            'end_mileage_reason' => 'Long distance transfer to Aberdeen',
            'mileage_variance_reason' => 'Logon reason',
        ]);
    }

    public function test_logoff_rejects_an_overlong_mileage_explanation(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 100]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [
                'endMileage' => 700,
                'mileageExplanation' => str_repeat('a', 2001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mileageExplanation']);
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

    public function test_logon_without_a_bike_creates_an_active_shift_with_no_bike(): void
    {
        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', ['riderId' => '100001'])
            ->assertCreated()
            ->assertJsonPath('riderId', '100001')
            ->assertJsonPath('bikeId', null)
            ->assertJsonPath('bikeRegistration', null)
            ->assertJsonPath('startMileage', null);

        $this->assertDatabaseHas('operational_shifts', [
            'bike_id' => null,
            'start_mileage' => null,
            'logged_off_at' => null,
        ]);

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->getJson('/api/shifts/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bikeRegistration', null);
    }

    public function test_two_riders_can_be_on_duty_without_a_bike(): void
    {
        OperationalShift::factory()->withoutBike()->create([
            'rider_id' => Rider::factory()->create(['external_id' => 100002])->id,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', ['riderId' => '100001'])
            ->assertCreated();

        $this->assertDatabaseCount('operational_shifts', 2);
    }

    public function test_a_bike_less_logon_still_rejects_a_rider_who_is_already_on_duty(): void
    {
        $rider = Rider::factory()->create(['external_id' => 100001]);
        OperationalShift::factory()->withoutBike()->create(['rider_id' => $rider->id]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', ['riderId' => '100001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['riderId']);
    }

    public function test_logon_rejects_a_bike_without_a_start_mileage(): void
    {
        $bike = Bike::factory()->create();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', ['riderId' => '100001', 'bikeId' => $bike->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['startMileage']);

        $this->assertDatabaseCount('operational_shifts', 0);
    }

    public function test_logon_rejects_a_start_mileage_without_a_bike(): void
    {
        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/shifts/logon', ['riderId' => '100001', 'startMileage' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);

        $this->assertDatabaseCount('operational_shifts', 0);
    }

    public function test_controller_can_assign_a_bike_to_a_shift_without_one(): void
    {
        $bike = $this->bikeInArea('E', ['registration' => 'CF12 ABC', 'initial_mileage' => 15234]);
        $shift = $this->bikelessShift();
        $loggedOnAt = $shift->logged_on_at->toIso8601String();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $bike->id,
                'startMileage' => 15234,
            ])
            ->assertOk()
            ->assertJsonPath('id', $shift->id)
            ->assertJsonPath('bikeId', $bike->id)
            ->assertJsonPath('bikeRegistration', 'CF12 ABC')
            ->assertJsonPath('startMileage', 15234)
            ->assertJsonPath('startedAt', $loggedOnAt);

        $this->assertDatabaseHas('operational_shifts', [
            'id' => $shift->id,
            'bike_id' => $bike->id,
            'start_mileage' => 15234,
            'logged_off_at' => null,
        ]);
    }

    public function test_assigning_a_bike_requires_a_reason_when_the_mileage_differs(): void
    {
        $bike = $this->bikeInArea('E', ['initial_mileage' => 15234]);
        $shift = $this->bikelessShift();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $bike->id,
                'startMileage' => 15300,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mileageVarianceReason']);

        $this->assertNull($shift->fresh()->bike_id);

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $bike->id,
                'startMileage' => 15300,
                'mileageVarianceReason' => 'Odometer replaced',
            ])
            ->assertOk()
            ->assertJsonPath('startMileage', 15300)
            ->assertJsonPath('mileageVarianceReason', 'Odometer replaced');
    }

    public function test_assigning_a_bike_rejects_a_bike_from_an_area_the_rider_does_not_cover(): void
    {
        $carmsBike = $this->bikeInArea('C', ['initial_mileage' => 4000]);
        $shift = $this->bikelessShift();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $carmsBike->id,
                'startMileage' => 4000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);

        $this->assertNull($shift->fresh()->bike_id);
    }

    public function test_assigning_a_bike_rejects_a_bike_already_on_another_active_shift(): void
    {
        $bike = $this->bikeInArea('E', ['initial_mileage' => 4000]);
        OperationalShift::factory()->create(['bike_id' => $bike->id, 'start_mileage' => 4000]);
        $shift = $this->bikelessShift();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $bike->id,
                'startMileage' => 4000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId']);
    }

    public function test_assigning_a_bike_rejects_a_shift_that_already_has_a_bike(): void
    {
        $existing = $this->bikeInArea('E', ['initial_mileage' => 100]);
        $other = $this->bikeInArea('E', ['initial_mileage' => 200]);
        $rider = Rider::factory()->create(['external_id' => 100001, 'name' => 'Alex Morgan']);
        $shift = OperationalShift::factory()->create([
            'rider_id' => $rider->id,
            'bike_id' => $existing->id,
            'start_mileage' => 100,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $other->id,
                'startMileage' => 200,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shift']);

        $this->assertSame($existing->id, $shift->fresh()->bike_id);
    }

    public function test_assigning_a_bike_rejects_a_logged_off_shift(): void
    {
        $bike = $this->bikeInArea('E', ['initial_mileage' => 200]);
        $rider = Rider::factory()->create(['external_id' => 100001, 'name' => 'Alex Morgan']);
        $shift = OperationalShift::factory()->withoutBike()->create([
            'rider_id' => $rider->id,
            'logged_off_at' => now(),
            'logged_off_by_user_id' => User::factory()->create()->id,
        ]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $bike->id,
                'startMileage' => 200,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shift']);

        $this->assertNull($shift->fresh()->bike_id);
    }

    public function test_assigning_a_bike_requires_a_bike_and_start_mileage(): void
    {
        $shift = $this->bikelessShift();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bikeId', 'startMileage']);
    }

    public function test_rider_cannot_assign_a_bike(): void
    {
        $bike = $this->bikeInArea('E', ['initial_mileage' => 200]);
        $shift = $this->bikelessShift();

        $this->fakeDirectory($this->riderVolunteer());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $bike->id,
                'startMileage' => 200,
            ])
            ->assertForbidden();
    }

    public function test_logoff_of_a_shift_without_a_bike_needs_no_mileage_or_bike_location(): void
    {
        $shift = $this->bikelessShift();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['faults' => 'None'])
            ->assertOk()
            ->assertJsonPath('id', $shift->id)
            ->assertJsonPath('bikeRegistration', null);

        $fresh = $shift->fresh();
        $this->assertNotNull($fresh->logged_off_at);
        $this->assertNull($fresh->end_mileage);
        $this->assertNull($fresh->end_mileage_reason);
        $this->assertSame('None', $fresh->faults);
        $this->assertDatabaseCount('mileage_readings', 0);
    }

    public function test_logoff_of_a_shift_with_a_bike_still_requires_end_mileage_and_bike_location(): void
    {
        $shift = OperationalShift::factory()->create(['start_mileage' => 100]);

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endMileage']);

        $this->assertNull($shift->fresh()->logged_off_at);
    }

    public function test_a_bike_assigned_later_is_logged_off_with_end_mileage(): void
    {
        $bike = $this->bikeInArea('E', ['initial_mileage' => 15234]);
        $shift = $this->bikelessShift();

        $this->fakeDirectory($this->controllerAndRiderVolunteers());
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/bike", [
                'bikeId' => $bike->id,
                'startMileage' => 15234,
            ])
            ->assertOk();

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endMileage']);

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->postJson("/api/shifts/{$shift->id}/logoff", ['endMileage' => 15300])
            ->assertOk();

        $this->assertSame(15300, $bike->fresh()->currentMileage());
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
                'startMileage' => $bike->currentMileage(),
            ])
            ->assertStatus(503);
    }

    private function bikelessShift(): OperationalShift
    {
        $rider = Rider::factory()->create(['external_id' => 100001, 'name' => 'Alex Morgan']);

        return OperationalShift::factory()->withoutBike()->create(['rider_id' => $rider->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bikeInArea(string $code, array $attributes = []): Bike
    {
        $area = ServiceArea::query()->where('code', $code)->firstOrFail();

        return Bike::factory()->create([...$attributes, 'service_area_id' => $area->id]);
    }

    /**
     * @param  list<string>  $roleNames
     * @return array<string, mixed>
     */
    private function riderVolunteer(array $roleNames = ['Rider', 'Rider - East']): array
    {
        return [
            'id' => 100001,
            'name' => 'Alex Morgan',
            'roles' => array_map(
                fn (string $name, int $index): array => ['role' => ['id' => 5001 + $index, 'name' => $name]],
                $roleNames,
                array_keys($roleNames),
            ),
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
     * @param  array<string, mixed>|null  $rider
     * @return list<array<string, mixed>>
     */
    private function controllerAndRiderVolunteers(?array $rider = null): array
    {
        $rider ??= $this->riderVolunteer();
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
            'www.3r.org.uk/shift.json*' => Http::response(['shifts' => []]),
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
