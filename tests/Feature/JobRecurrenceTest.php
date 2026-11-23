<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\DeliveryRequest;
use App\Models\JobContentType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsServiceAreas;
use Tests\Concerns\SignsInVolunteers;
use Tests\TestCase;

class JobRecurrenceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsServiceAreas;
    use SignsInVolunteers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useThreeRingsConfig();
        $this->seedServiceAreas();

        JobContentType::query()->firstOrCreate(
            ['name' => 'Blood samples'],
            ['active' => true, 'sort_order' => 1],
        );
    }

    public function test_a_controller_creates_a_weekly_series_and_materializes_occurrences(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-07 09:00:00', 'Europe/London'));
        $this->signInAsController();

        $response = $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $this->weeklyPayload([
                'startsOn' => '2026-12-07',
                'byWeekday' => [1],
                'horizonDays' => 14,
                'timeOfDay' => '10:30',
            ]));

        $response->assertCreated()
            ->assertJsonPath('frequency', 'weekly')
            ->assertJsonPath('isActive', true)
            ->assertJsonPath('timeOfDay', '10:30');

        $recurrenceId = $response->json('id');
        $this->assertNotEmpty($recurrenceId);

        // Mon 7th, 14th, 21st within 14-day horizon from 7 Dec 2026.
        $this->assertSame(3, DeliveryRequest::query()->where('recurrence_id', $recurrenceId)->count());
        $this->assertTrue(
            DeliveryRequest::query()
                ->where('recurrence_id', $recurrenceId)
                ->where('status', JobStatus::New)
                ->whereDate('occurrence_date', '2026-12-07')
                ->exists(),
        );

        $job = $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->json('data.0');

        $this->assertSame($recurrenceId, $job['recurrenceId']);
        $this->assertNotNull($job['occurrenceDate']);
        $this->assertNotNull($job['scheduledCollectionAt']);
    }

    public function test_materialize_command_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-07 09:00:00', 'Europe/London'));
        $this->signInAsController();

        $id = $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $this->weeklyPayload([
                'startsOn' => '2026-12-07',
                'byWeekday' => [1],
                'horizonDays' => 7,
            ]))
            ->assertCreated()
            ->json('id');

        $before = DeliveryRequest::query()->where('recurrence_id', $id)->count();
        Artisan::call('jobs:materialize-recurrences');
        $after = DeliveryRequest::query()->where('recurrence_id', $id)->count();

        $this->assertSame($before, $after);
        $this->assertStringContainsString('Materialized 0 occurrence(s).', Artisan::output());
    }

    public function test_pause_stops_new_materialization_and_resume_tops_up(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-07 09:00:00', 'Europe/London'));
        $this->signInAsController();

        $id = $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $this->dailyPayload([
                'startsOn' => '2026-12-07',
                'horizonDays' => 2,
            ]))
            ->assertCreated()
            ->json('id');

        $this->withToken('valid-token')
            ->postJson("/api/job-recurrences/{$id}/pause")
            ->assertOk()
            ->assertJsonPath('isActive', false);

        Carbon::setTestNow(Carbon::parse('2026-12-10 09:00:00', 'Europe/London'));
        Artisan::call('jobs:materialize-recurrences');
        $pausedCount = DeliveryRequest::query()->where('recurrence_id', $id)->count();

        $this->withToken('valid-token')
            ->postJson("/api/job-recurrences/{$id}/resume")
            ->assertOk()
            ->assertJsonPath('isActive', true);

        $resumedCount = DeliveryRequest::query()->where('recurrence_id', $id)->count();
        $this->assertGreaterThan($pausedCount, $resumedCount);
    }

    public function test_delete_pauses_and_cancels_future_new_occurrences(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-07 09:00:00', 'Europe/London'));
        $this->signInAsController();

        $id = $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $this->dailyPayload([
                'startsOn' => '2026-12-07',
                'horizonDays' => 3,
            ]))
            ->assertCreated()
            ->json('id');

        $this->withToken('valid-token')
            ->deleteJson("/api/job-recurrences/{$id}")
            ->assertOk()
            ->assertJsonPath('isActive', false);

        $statuses = DeliveryRequest::query()
            ->where('recurrence_id', $id)
            ->pluck('status')
            ->map(static fn ($status) => $status->value)
            ->all();

        $this->assertNotEmpty($statuses);
        $this->assertTrue(collect($statuses)->every(static fn (string $status): bool => $status === JobStatus::Cancelled->value));
    }

    public function test_weekly_requires_weekdays(): void
    {
        $this->signInAsController();

        $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $this->weeklyPayload([
                'byWeekday' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['byWeekday']);
    }

    public function test_rider_cannot_create_a_series(): void
    {
        $this->fakeDirectory([$this->volunteer('Rider')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $this->dailyPayload())
            ->assertForbidden();
    }

    public function test_list_returns_series_for_viewers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-07 09:00:00', 'Europe/London'));
        $this->signInAsController();
        $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $this->dailyPayload(['horizonDays' => 1]))
            ->assertCreated();

        $this->withToken('valid-token')
            ->getJson('/api/job-recurrences')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function frequencyProvider(): array
    {
        return [
            'daily' => ['daily'],
            'weekly' => ['weekly'],
        ];
    }

    #[DataProvider('frequencyProvider')]
    public function test_scheduled_time_is_stored_in_utc(string $frequency): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-07 09:00:00', 'Europe/London'));
        $this->signInAsController();

        $payload = $frequency === 'weekly'
            ? $this->weeklyPayload([
                'startsOn' => '2026-12-07',
                'byWeekday' => [1],
                'horizonDays' => 1,
                'timeOfDay' => '10:00',
                'timezone' => 'Europe/London',
            ])
            : $this->dailyPayload([
                'startsOn' => '2026-12-07',
                'horizonDays' => 1,
                'timeOfDay' => '10:00',
                'timezone' => 'Europe/London',
            ]);

        $id = $this->withToken('valid-token')
            ->postJson('/api/job-recurrences', $payload)
            ->assertCreated()
            ->json('id');

        $request = DeliveryRequest::query()->where('recurrence_id', $id)->firstOrFail();
        $this->assertSame(
            '2026-12-07 10:00:00',
            $request->scheduled_collection_at->timezone('Europe/London')->format('Y-m-d H:i:s'),
        );
    }

    private function signInAsController(): void
    {
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function weeklyPayload(array $overrides = []): array
    {
        return array_merge($this->baseBlueprint(), [
            'frequency' => 'weekly',
            'byWeekday' => [1, 3, 5],
            'startsOn' => now()->toDateString(),
            'timeOfDay' => '09:00',
            'horizonDays' => 14,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dailyPayload(array $overrides = []): array
    {
        return array_merge($this->baseBlueprint(), [
            'frequency' => 'daily',
            'startsOn' => now()->toDateString(),
            'timeOfDay' => '09:00',
            'horizonDays' => 14,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseBlueprint(): array
    {
        return [
            'jobType' => 'standard',
            'sender' => [
                'name' => 'Dr Patel',
                'phone' => '029 2074 7747',
                'organisation' => 'University Hospital of Wales',
            ],
            'collection' => [
                'placeId' => 'ChIJRecurrenceCollection0001',
                'address' => 'University Hospital of Wales, Cardiff CF14 4XW',
                'postcode' => 'CF14 4XW',
                'latitude' => 51.5068,
                'longitude' => -3.1918,
            ],
            'delivery' => [
                'placeId' => 'ChIJRecurrenceDelivery0001',
                'address' => 'Singleton Hospital, Swansea SA2 8QA',
                'postcode' => 'SA2 8QA',
                'latitude' => 51.6103,
                'longitude' => -3.9803,
            ],
            'contents' => 'Blood samples',
            'serviceAreas' => ['E'],
            'timezone' => 'Europe/London',
        ];
    }
}
