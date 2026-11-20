<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BikeIndexTest extends TestCase
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

    public function test_lists_active_bikes_in_registration_order(): void
    {
        $east = ServiceArea::query()->firstOrCreate(['code' => 'E'], ['full_name' => 'East']);
        $northWest = ServiceArea::query()->firstOrCreate(['code' => 'NW'], ['full_name' => 'North West']);

        Bike::factory()->create([
            'registration' => 'CF34 DEF',
            'initial_mileage' => 9820,
            'service_area_id' => $northWest->id,
        ]);
        Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'initial_mileage' => 15234,
            'service_area_id' => $east->id,
        ]);
        Bike::factory()->retired()->create([
            'registration' => 'CF56 GHI',
        ]);

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response([
                'volunteers' => [$this->controllerVolunteer()],
            ]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/bikes')
            ->assertOk()
            ->assertJsonPath('data.0.registration', 'CF12 ABC')
            ->assertJsonPath('data.0.lastRecordedMileage', 15234)
            ->assertJsonPath('data.0.area', 'E')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.1.registration', 'CF34 DEF');
    }

    public function test_returns_the_faults_from_the_latest_logged_off_shift_with_the_time(): void
    {
        $bike = Bike::factory()->create(['registration' => 'CF12 ABC']);
        OperationalShift::factory()->loggedOff()->create([
            'bike_id' => $bike->id,
            'faults' => 'Old fault',
            'logged_off_at' => '2026-11-10 17:00:00',
        ]);
        OperationalShift::factory()->loggedOff()->create([
            'bike_id' => $bike->id,
            'faults' => 'Rear brake feels soft',
            'logged_off_at' => '2026-11-12 18:30:00',
        ]);

        $this->getBikes()
            ->assertOk()
            ->assertJsonPath('data.0.lastShiftNote', 'Rear brake feels soft')
            ->assertJsonPath('data.0.lastShiftNoteAt', '2026-11-12T18:30:00+00:00');
    }

    public function test_an_older_shifts_faults_are_not_returned_when_the_latest_shift_had_none(): void
    {
        $bike = Bike::factory()->create();
        OperationalShift::factory()->loggedOff()->create([
            'bike_id' => $bike->id,
            'faults' => 'Rear brake feels soft',
            'logged_off_at' => '2026-11-10 17:00:00',
        ]);
        OperationalShift::factory()->loggedOff()->create([
            'bike_id' => $bike->id,
            'faults' => null,
            'logged_off_at' => '2026-11-12 18:30:00',
        ]);

        $this->getBikes()
            ->assertOk()
            ->assertJsonPath('data.0.lastShiftNote', null)
            ->assertJsonPath('data.0.lastShiftNoteAt', null);
    }

    public function test_a_bike_with_no_shifts_has_no_note(): void
    {
        Bike::factory()->create();

        $this->getBikes()
            ->assertOk()
            ->assertJsonPath('data.0.lastShiftNote', null)
            ->assertJsonPath('data.0.lastShiftNoteAt', null);
    }

    public function test_an_active_shift_does_not_hide_or_replace_the_note(): void
    {
        $bike = Bike::factory()->create();
        OperationalShift::factory()->loggedOff()->create([
            'bike_id' => $bike->id,
            'faults' => 'Rear brake feels soft',
            'logged_off_at' => '2026-11-10 17:00:00',
        ]);
        OperationalShift::factory()->create([
            'bike_id' => $bike->id,
            'faults' => 'Not finished yet',
        ]);

        $this->getBikes()
            ->assertOk()
            ->assertJsonPath('data.0.lastShiftNote', 'Rear brake feels soft');
    }

    public function test_a_shift_logged_on_without_a_bike_never_counts_as_the_latest_shift(): void
    {
        $bike = Bike::factory()->create();
        OperationalShift::factory()->loggedOff()->create([
            'bike_id' => $bike->id,
            'faults' => 'Rear brake feels soft',
            'logged_off_at' => '2026-11-10 17:00:00',
        ]);
        OperationalShift::factory()->withoutBike()->loggedOff()->create([
            'faults' => 'Bikeless shift note',
            'logged_off_at' => '2026-11-12 18:30:00',
        ]);

        $this->getBikes()
            ->assertOk()
            ->assertJsonPath('data.0.lastShiftNote', 'Rear brake feels soft');
    }

    public function test_notes_are_loaded_without_a_query_per_bike(): void
    {
        foreach (['AA11 AAA', 'BB22 BBB', 'CC33 CCC'] as $registration) {
            $bike = Bike::factory()->create(['registration' => $registration]);
            OperationalShift::factory()->loggedOff()->create([
                'bike_id' => $bike->id,
                'faults' => "Fault on {$registration}",
            ]);
        }

        $this->fakeController();

        DB::enableQueryLog();
        $response = $this->withToken('valid-token')->getJson('/api/bikes')->assertOk();
        $shiftQueries = collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => str_contains($query['query'], '"operational_shifts" as "latest"'))
            ->count();

        $response->assertJsonPath('data.2.lastShiftNote', 'Fault on CC33 CCC');
        $this->assertSame(1, $shiftQueries);
    }

    public function test_the_latest_shift_lookup_has_a_supporting_index(): void
    {
        $this->assertTrue(Schema::hasIndex(
            'operational_shifts',
            'operational_shifts_bike_id_logged_off_at_id_index',
        ));
        $this->assertTrue(Schema::hasIndex(
            'operational_shifts',
            ['bike_id', 'logged_off_at', 'id'],
        ));
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/bikes')->assertUnauthorized();
    }

    private function fakeController(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response([
                'volunteers' => [$this->controllerVolunteer()],
            ]),
        ]);
        $this->mockVerifiedClaims();
    }

    private function getBikes(): TestResponse
    {
        $this->fakeController();

        return $this->withToken('valid-token')->getJson('/api/bikes');
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
