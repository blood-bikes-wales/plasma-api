<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Services\Shifts\ShiftTypeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShiftTypeTest extends TestCase
{
    use RefreshDatabase;

    private const RIDER_ID = 100001;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-25 02:00:00');

        config()->set('services.three_rings', [
            'base_url' => 'https://www.3r.org.uk',
            'key' => 'test-key',
            'contact_email' => 'dev@bloodbikes.wales',
            'timeout_seconds' => 30,
            'connect_timeout_seconds' => 5,
            'rate_limit' => ['max_attempts' => 15, 'decay_seconds' => 60],
            'cache' => [
                'volunteers' => ['fresh' => 3600, 'stale' => 86400],
                'shifts' => ['fresh' => 300, 'stale' => 3600],
            ],
        ]);

        ServiceArea::query()->create([
            'code' => 'E',
            'full_name' => 'East',
            'three_rings_role' => 'Rider - East',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_rostered_rider_gets_the_rota_name_by_default(): void
    {
        $this->fakeThreeRings([$this->shift(1, 'Night Cover', 'Milk', '2026-07-24T19:00:00.000Z', [self::RIDER_ID])]);

        $this->logOn()
            ->assertCreated()
            ->assertJsonPath('shiftType', 'Milk');

        $this->assertDatabaseHas('operational_shifts', ['shift_type' => 'Milk']);

        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->getJson('/api/shifts/active')
            ->assertOk()
            ->assertJsonPath('data.0.shiftType', 'Milk');
    }

    public function test_the_title_source_uses_the_shift_title(): void
    {
        config()->set('services.three_rings.shift_type_source', 'title');
        $this->fakeThreeRings([$this->shift(1, 'Sepsis', 'North Wales', '2026-07-24T19:00:00.000Z', [self::RIDER_ID])]);

        $this->logOn()
            ->assertCreated()
            ->assertJsonPath('shiftType', 'Sepsis');
    }

    public function test_an_unknown_source_falls_back_to_the_rota_name(): void
    {
        config()->set('services.three_rings.shift_type_source', 'nonsense');
        $this->fakeThreeRings([$this->shift(1, 'Night Cover', 'Swansea Bay', '2026-07-24T19:00:00.000Z', [self::RIDER_ID])]);

        $this->logOn()
            ->assertCreated()
            ->assertJsonPath('shiftType', 'Swansea Bay');
    }

    public function test_an_unrostered_rider_logs_on_with_no_shift_type(): void
    {
        $this->fakeThreeRings([$this->shift(1, 'Night Cover', 'Milk', '2026-07-24T19:00:00.000Z', [100002, 100003])]);

        $this->logOn()
            ->assertCreated()
            ->assertJsonPath('shiftType', null);

        $this->assertDatabaseHas('operational_shifts', ['shift_type' => null]);
    }

    public function test_volunteer_ids_are_matched_as_integers_not_strings(): void
    {
        // Three Rings may send ids as strings; the data class casts them to int.
        $this->fakeThreeRings([$this->shift(1, 'Night Cover', 'Milk', '2026-07-24T19:00:00.000Z', ['100001'])]);

        $this->logOn()
            ->assertCreated()
            ->assertJsonPath('shiftType', 'Milk');
    }

    public function test_three_rings_failures_never_fail_the_logon(): void
    {
        $failures = [
            'server error' => fn () => Http::response('boom', 500),
            'rate limited' => fn () => Http::response('slow down', 429),
            'forbidden' => fn () => Http::response('no', 403),
            'timeout' => fn () => throw new ConnectionException('cURL error 28: timed out'),
        ];

        foreach ($failures as $label => $failure) {
            OperationalShift::query()->delete();
            Rider::query()->delete();
            $this->app['cache']->flush();

            Http::fake([
                'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => [$this->riderVolunteer(), $this->controllerVolunteer()]]),
                'www.3r.org.uk/shift.json*' => $failure,
            ]);

            $this->logOn()
                ->assertCreated()
                ->assertJsonPath('shiftType', null);

            $this->assertDatabaseCount('operational_shifts', 1);
            $this->assertDatabaseHas('operational_shifts', ['shift_type' => null, 'logged_off_at' => null]);
        }
    }

    public function test_a_failed_lookup_logs_a_warning(): void
    {
        Log::spy();
        Http::fake([
            'www.3r.org.uk/shift.json*' => Http::response('boom', 500),
        ]);

        $this->assertNull($this->resolver()->resolve(self::RIDER_ID, CarbonImmutable::now()));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_unexpected_error_in_the_lookup_is_swallowed(): void
    {
        Http::fake([
            'www.3r.org.uk/shift.json*' => Http::response(['shifts' => [['shift' => ['start_datetime' => 'not a date']]]]),
        ]);

        $this->assertNull($this->resolver()->resolve(self::RIDER_ID, CarbonImmutable::now()));
    }

    public function test_the_lookup_is_a_bounded_get_with_a_window_around_the_logon_time(): void
    {
        $this->fakeThreeRings([]);

        $this->logOn()->assertCreated();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/shift.json')
                && str_contains($request->url(), 'start_date=2026-07-24')
                && str_contains($request->url(), 'end_date=2026-07-26')
                && $request->hasHeader('Authorization', 'APIKEY test-key');
        });
        Http::assertSentCount(2); // directory + shifts, no writes, no retries
    }

    public function test_a_shift_that_spans_midnight_is_matched_after_midnight(): void
    {
        // 19:00 + 12h: logon at 02:00 the next day is inside it.
        $this->fakeThreeRings([$this->shift(1, 'Night Cover', 'Milk', '2026-07-24T19:00:00.000Z', [self::RIDER_ID])]);

        $this->logOn()
            ->assertCreated()
            ->assertJsonPath('shiftType', 'Milk');
    }

    public function test_the_shift_containing_the_logon_time_beats_a_nearer_start(): void
    {
        $this->fakeThreeRings([
            // Starts 3h from now (nearer) but not yet begun.
            $this->shift(2, 'Day', 'Sepsis', '2026-07-25T05:00:00.000Z', [self::RIDER_ID]),
            // Started 7h ago, contains 02:00.
            $this->shift(1, 'Night', 'Milk', '2026-07-24T19:00:00.000Z', [self::RIDER_ID]),
        ]);

        $this->logOn()->assertCreated()->assertJsonPath('shiftType', 'Milk');
    }

    public function test_with_no_containing_shift_the_nearest_upcoming_start_wins(): void
    {
        $at = CarbonImmutable::now();

        $this->fakeThreeRings([
            $this->shift(1, 'Ended', 'Swansea Bay', $at->subHours(20)->toIso8601String(), [self::RIDER_ID], 3600),
            $this->shift(3, 'Later', 'Milk', $at->addHours(10)->toIso8601String(), [self::RIDER_ID], 3600),
            $this->shift(2, 'Soon', 'Sepsis', $at->addHours(2)->toIso8601String(), [self::RIDER_ID], 3600),
        ]);

        $this->assertSame('Sepsis', $this->resolver()->resolve(self::RIDER_ID, $at));
    }

    public function test_a_rider_logging_on_early_gets_the_shift_that_has_not_started_yet(): void
    {
        $at = CarbonImmutable::now();

        $this->fakeThreeRings([
            $this->shift(1, 'Day', 'Milk', $at->addMinutes(30)->toIso8601String(), [self::RIDER_ID]),
        ]);

        $this->assertSame('Milk', $this->resolver()->resolve(self::RIDER_ID, $at));
    }

    public function test_a_shift_that_already_ended_is_not_a_match(): void
    {
        $at = CarbonImmutable::now();

        $this->fakeThreeRings([
            // Yesterday evening, ended 3h ago.
            $this->shift(1, 'Ended', 'Milk', $at->subHours(15)->toIso8601String(), [self::RIDER_ID], 12 * 3600),
        ]);

        $this->assertNull($this->resolver()->resolve(self::RIDER_ID, $at));
    }

    public function test_an_ended_shift_is_skipped_in_favour_of_a_later_one_today(): void
    {
        $at = CarbonImmutable::now();

        $this->fakeThreeRings([
            $this->shift(1, 'Ended', 'Milk', $at->subHours(3)->toIso8601String(), [self::RIDER_ID], 3600),
            $this->shift(2, 'Later today', 'Sepsis', $at->addHours(9)->toIso8601String(), [self::RIDER_ID], 3600),
        ]);

        $this->assertSame('Sepsis', $this->resolver()->resolve(self::RIDER_ID, $at));
    }

    public function test_a_shift_ending_exactly_now_has_ended(): void
    {
        $at = CarbonImmutable::now();

        $this->fakeThreeRings([
            $this->shift(1, 'Ending', 'Milk', $at->subHour()->toIso8601String(), [self::RIDER_ID], 3600),
        ]);

        $this->assertNull($this->resolver()->resolve(self::RIDER_ID, $at));
    }

    public function test_shifts_that_start_more_than_a_day_away_are_ignored(): void
    {
        $at = CarbonImmutable::now();

        $this->fakeThreeRings([
            $this->shift(1, 'Old', 'Milk', $at->subHours(30)->toIso8601String(), [self::RIDER_ID], 3600),
            $this->shift(2, 'Future', 'Sepsis', $at->addHours(30)->toIso8601String(), [self::RIDER_ID], 3600),
        ]);

        $this->assertNull($this->resolver()->resolve(self::RIDER_ID, $at));
    }

    public function test_a_rider_without_an_external_id_skips_the_lookup(): void
    {
        Http::fake();

        $this->assertNull($this->resolver()->resolve(0, CarbonImmutable::now()));

        Http::assertNothingSent();
    }

    public function test_the_label_is_trimmed_and_blank_becomes_null(): void
    {
        $this->fakeThreeRings([$this->shift(1, 'Night Cover', "  Milk \n", '2026-07-24T19:00:00.000Z', [self::RIDER_ID])]);

        $this->assertSame('Milk', $this->resolver()->resolve(self::RIDER_ID, CarbonImmutable::now()));
    }

    public function test_a_blank_label_becomes_null(): void
    {
        $this->fakeThreeRings([$this->shift(1, 'Night Cover', '   ', '2026-07-24T19:00:00.000Z', [self::RIDER_ID])]);

        $this->assertNull($this->resolver()->resolve(self::RIDER_ID, CarbonImmutable::now()));
    }

    public function test_a_blank_title_does_not_fall_back_to_the_rota_name(): void
    {
        config()->set('services.three_rings.shift_type_source', 'title');
        $this->fakeThreeRings([$this->shift(1, '', 'Milk', '2026-07-24T19:00:00.000Z', [self::RIDER_ID])]);

        $this->assertNull($this->resolver()->resolve(self::RIDER_ID, CarbonImmutable::now()));
    }

    public function test_shifts_without_a_stored_type_report_null(): void
    {
        $rider = Rider::factory()->create(['external_id' => 100002]);
        OperationalShift::factory()->create(['rider_id' => $rider->id]);

        $this->mockVerifiedClaims();
        $this->fakeThreeRings([]);

        $this->withToken('valid-token')
            ->getJson('/api/shifts/active')
            ->assertOk()
            ->assertJsonPath('data.0.shiftType', null);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/shift.json'));
    }

    private function resolver(): ShiftTypeResolver
    {
        return $this->app->make(ShiftTypeResolver::class);
    }

    private function logOn(): TestResponse
    {
        $bike = Bike::factory()->create(['initial_mileage' => 15234]);
        $this->mockVerifiedClaims();

        return $this->withToken('valid-token')->postJson('/api/shifts/logon', [
            'riderId' => (string) self::RIDER_ID,
            'bikeId' => $bike->id,
            'startMileage' => 15234,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $shifts
     */
    private function fakeThreeRings(array $shifts): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => [$this->riderVolunteer(), $this->controllerVolunteer()]]),
            'www.3r.org.uk/shift.json*' => Http::response(['shifts' => $shifts]),
        ]);
    }

    /**
     * @param  list<int|string>  $volunteerIds
     * @return array<string, mixed>
     */
    private function shift(int $id, string $title, string $rota, string $start, array $volunteerIds, int $duration = 43200): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'start_datetime' => $start,
            'duration' => $duration,
            'rota' => ['id' => 7000 + $id, 'name' => $rota],
            'volunteer_shifts' => array_map(
                fn (int|string $volunteerId): array => ['id' => 1, 'volunteer' => ['id' => $volunteerId, 'name' => 'Someone']],
                $volunteerIds,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function riderVolunteer(): array
    {
        return [
            'id' => self::RIDER_ID,
            'name' => 'Alex Morgan',
            'roles' => [['role' => ['id' => 5001, 'name' => 'Rider']], ['role' => ['id' => 5002, 'name' => 'Rider - East']]],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'alex@bloodbikes.wales']],
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
            'roles' => [['role' => ['id' => 5003, 'name' => 'Controller']]],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
            ],
        ];
    }

    private function mockVerifiedClaims(): void
    {
        $this->mock(GoogleIdTokenVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')
                ->with('valid-token')
                ->andReturn([
                    'sub' => 'google-sub-123',
                    'email' => 'rider@bloodbikes.wales',
                    'email_verified' => true,
                    'hd' => 'bloodbikes.wales',
                    'name' => 'Test Rider',
                ]);
        });
    }
}
