<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInVolunteers;
use Tests\TestCase;

class ScheduleDeliveryJobTest extends TestCase
{
    use RefreshDatabase, SignsInVolunteers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useThreeRingsConfig();
        Carbon::setTestNow('2026-11-20 12:00:00');
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        $this->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertUnauthorized();
    }

    #[DataProvider('rolesThatCannotCreateJobs')]
    public function test_roles_that_cannot_create_jobs_cannot_schedule(string $role): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        $this->signInAs($role);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertForbidden();

        $this->assertNull($job->fresh()->scheduled_collection_at);
    }

    public function test_a_controller_schedules_a_milk_job_and_the_reminder_clears(): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('needsMotherContact', true)
            ->assertJsonPath('scheduledCollectionAt', null);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertOk()
            ->assertJsonPath('id', $job->id)
            ->assertJsonPath('scheduledCollectionAt', '2026-11-27T10:00:00+00:00')
            ->assertJsonPath('needsMotherContact', false)
            ->assertJsonPath('status', JobStatus::New->value)
            ->assertJsonPath('mother.name', 'Megan Evans');

        $this->assertSame('2026-11-27 10:00:00', $job->fresh()->scheduled_collection_at->format('Y-m-d H:i:s'));

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertJsonPath('data.0.needsMotherContact', false)
            ->assertJsonPath('data.0.scheduledCollectionAt', '2026-11-27T10:00:00+00:00');
    }

    public function test_an_admin_can_schedule(): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        User::factory()->admin()->create(['email' => 'rider@bloodbikes.wales', 'google_id' => null]);
        Http::fake(['www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []])]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertOk()
            ->assertJsonPath('needsMotherContact', false);

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$job->id}")
            ->assertJsonPath('needsMotherContact', false);
    }

    public function test_a_time_with_an_offset_is_stored_as_the_same_instant_in_utc(): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00+05:00'])
            ->assertOk()
            ->assertJsonPath('scheduledCollectionAt', '2026-11-27T05:00:00+00:00');

        $this->assertSame('2026-11-27 05:00:00', $job->fresh()->scheduled_collection_at->format('Y-m-d H:i:s'));
    }

    public function test_a_milk_time_that_is_in_the_future_only_because_of_its_offset_is_judged_as_an_instant(): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        $this->signInAs('Controller');

        // 12:30 at +02:00 is 10:30 UTC, which is before "now" (12:00 UTC) even though 12:30 is not.
        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-20T12:30:00+02:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduledCollectionAt']);

        // And 11:30 at -01:00 is 12:30 UTC: in the future.
        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-20T11:30:00-01:00'])
            ->assertOk()
            ->assertJsonPath('scheduledCollectionAt', '2026-11-20T12:30:00+00:00');
    }

    public function test_a_past_time_on_a_milk_job_is_rejected_and_changes_nothing(): void
    {
        $job = DeliveryJob::factory()->milk()->create(['updated_at' => '2026-11-19 09:00:00']);

        $this->signInAs('Controller');

        foreach (['2026-11-19T10:00:00Z', '2026-11-20T12:00:00Z'] as $time) {
            $this->withToken('valid-token')
                ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => $time])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['scheduledCollectionAt']);
        }

        $fresh = $job->fresh();
        $this->assertNull($fresh->scheduled_collection_at);
        $this->assertSame('2026-11-19 09:00:00', $fresh->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_a_standard_job_may_be_given_any_time_including_one_in_the_past(): void
    {
        $job = DeliveryJob::factory()->create();

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-18T08:00:00Z'])
            ->assertOk()
            ->assertJsonPath('scheduledCollectionAt', '2026-11-18T08:00:00+00:00')
            ->assertJsonPath('needsMotherContact', false);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-30T08:00:00Z'])
            ->assertOk()
            ->assertJsonPath('scheduledCollectionAt', '2026-11-30T08:00:00+00:00');
    }

    public function test_a_scheduled_job_can_be_rescheduled_while_new_or_allocated(): void
    {
        $new = DeliveryJob::factory()->milk()->create(['scheduled_collection_at' => '2026-11-25 09:00:00']);
        $allocated = DeliveryJob::factory()->milk()->allocated()->create();

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$new->id}/schedule", ['scheduledCollectionAt' => '2026-11-26T09:00:00Z'])
            ->assertOk()
            ->assertJsonPath('scheduledCollectionAt', '2026-11-26T09:00:00+00:00');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$allocated->id}/schedule", ['scheduledCollectionAt' => '2026-11-26T09:00:00Z'])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Allocated->value)
            ->assertJsonPath('scheduledCollectionAt', '2026-11-26T09:00:00+00:00');
    }

    #[DataProvider('statusesThatCannotBeScheduled')]
    public function test_a_job_that_is_collected_or_finished_cannot_be_scheduled(string $state): void
    {
        $job = DeliveryJob::factory()->milk()->{$state}()->create([
            'scheduled_collection_at' => '2026-11-18 09:00:00',
            'updated_at' => '2026-11-19 09:00:00',
        ]);

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);

        $fresh = $job->fresh();
        $this->assertSame('2026-11-18 09:00:00', $fresh->scheduled_collection_at->format('Y-m-d H:i:s'));
        // A rejected call must not touch updated_at: the Completed list reads it as the finish time.
        $this->assertSame('2026-11-19 09:00:00', $fresh->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_a_standard_collected_job_cannot_be_scheduled_either(): void
    {
        $job = DeliveryJob::factory()->collected()->create(['updated_at' => '2026-11-19 09:00:00']);

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);

        $this->assertNull($job->fresh()->scheduled_collection_at);
        $this->assertSame('2026-11-19 09:00:00', $job->fresh()->updated_at->format('Y-m-d H:i:s'));
    }

    #[DataProvider('badTimes')]
    public function test_the_time_is_required_and_must_be_a_date(mixed $time): void
    {
        $job = DeliveryJob::factory()->create();

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", ['scheduledCollectionAt' => $time])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduledCollectionAt']);

        $this->assertNull($job->fresh()->scheduled_collection_at);
    }

    public function test_a_missing_time_is_rejected(): void
    {
        $job = DeliveryJob::factory()->create();

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/schedule", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduledCollectionAt']);
    }

    public function test_an_unknown_or_malformed_job_id_is_not_found(): void
    {
        $this->signInAs('Controller');

        $body = ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'];

        $this->withToken('valid-token')->postJson('/api/jobs/'.Str::uuid().'/schedule', $body)->assertNotFound();
        $this->withToken('valid-token')->postJson('/api/jobs/not-a-uuid/schedule', $body)->assertNotFound();
    }

    public function test_scheduling_a_relay_sets_the_shared_request_time_on_every_leg(): void
    {
        $seed = DeliveryJob::factory()->milk()->create();
        $requestId = $seed->request_id;
        $legOne = DeliveryJob::factory()->milk()->collected()->create([
            'request_id' => $requestId,
            'leg_number' => 1,
        ]);
        $legTwo = DeliveryJob::factory()->milk()->create([
            'request_id' => $requestId,
            'leg_number' => 2,
        ]);

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$requestId}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertOk()
            ->assertJsonPath('needsMotherContact', false)
            ->assertJsonPath('scheduledCollectionAt', '2026-11-27T10:00:00+00:00')
            ->assertJsonPath('legs.0.scheduledCollectionAt', '2026-11-27T10:00:00+00:00')
            ->assertJsonPath('legs.1.scheduledCollectionAt', '2026-11-27T10:00:00+00:00');

        $this->assertSame('2026-11-27 10:00:00', $legTwo->fresh()->scheduled_collection_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-27 10:00:00', $legOne->fresh()->scheduled_collection_at->format('Y-m-d H:i:s'));
    }

    public function test_a_relay_leg_cannot_be_scheduled_on_its_own(): void
    {
        $seed = DeliveryJob::factory()->create();
        $requestId = $seed->request_id;
        $leg = DeliveryJob::factory()->create(['request_id' => $requestId, 'leg_number' => 1]);
        DeliveryJob::factory()->create(['request_id' => $requestId, 'leg_number' => 2]);

        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$leg->id}/schedule", ['scheduledCollectionAt' => '2026-11-27T10:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);

        $this->assertNull($leg->fresh()->scheduled_collection_at);
    }

    public function test_needs_mother_contact_is_only_true_for_an_unscheduled_new_milk_job(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        DeliveryJob::factory()->milk()->create(['reference' => 'JB-3001']);
        Carbon::setTestNow('2026-11-20 10:01:00');
        DeliveryJob::factory()->create(['reference' => 'JB-3002']);
        Carbon::setTestNow('2026-11-20 10:02:00');
        DeliveryJob::factory()->milk()->allocated()->create(['reference' => 'JB-3003']);
        Carbon::setTestNow('2026-11-20 10:03:00');
        DeliveryJob::factory()->milk()->create(['reference' => 'JB-3004', 'scheduled_collection_at' => '2026-11-28 09:00:00']);
        Carbon::setTestNow('2026-11-20 12:00:00');

        $this->signInAs('Controller');

        $flags = collect($this->withToken('valid-token')->getJson('/api/jobs/active')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $job): array => [$job['reference'] => $job['needsMotherContact']]);

        $this->assertSame([
            'JB-3004' => false,
            'JB-3003' => false,
            'JB-3002' => false,
            'JB-3001' => true,
        ], $flags->all());
    }

    #[DataProvider('rolesThatCannotCreateJobs')]
    public function test_roles_that_cannot_create_jobs_are_not_told_about_the_reminder(string $role): void
    {
        $job = DeliveryJob::factory()->milk()->create(['scheduled_collection_at' => '2026-11-28 09:00:00']);

        $this->signInAs($role);

        // They already see the job's other timings, so the arranged time is no new exposure; the controller-only flag is absent.
        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('scheduledCollectionAt', '2026-11-28T09:00:00+00:00')
            ->assertJsonMissingPath('needsMotherContact')
            ->assertJsonMissingPath('mother');

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonMissingPath('data.0.needsMotherContact');
    }

    public function test_the_directory_endpoints_do_not_return_jobs(): void
    {
        DeliveryJob::factory()->milk()->create();

        $this->signInAs('Rider');

        foreach (['/api/directory/volunteers', '/api/directory/bikes'] as $path) {
            $body = $this->withToken('valid-token')->getJson($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('needsMotherContact', $body);
            $this->assertStringNotContainsString('scheduledCollectionAt', $body);
        }
    }

    public function test_listing_does_not_query_more_per_milk_job(): void
    {
        $this->signInAs('Controller');
        $this->withToken('valid-token')->getJson('/api/me')->assertOk();

        DeliveryJob::factory()->milk()->count(2)->create();
        $small = $this->queriesFor('/api/jobs/active');

        DeliveryJob::factory()->milk()->count(6)->create();
        $large = $this->queriesFor('/api/jobs/active');

        $this->assertSame($small, $large);
    }

    private function queriesFor(string $path): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken('valid-token')->getJson($path)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function signInAs(string $role): void
    {
        $this->fakeDirectory([$this->volunteer($role)]);
        $this->mockVerifiedClaims();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesThatCannotCreateJobs(): array
    {
        return [
            'rider' => ['Rider'],
            'driver' => ['Driver'],
            'trustee' => ['Trustee'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusesThatCannotBeScheduled(): array
    {
        return [
            'collected' => ['collected'],
            'delivered' => ['delivered'],
            'cancelled' => ['cancelled'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badTimes(): array
    {
        return [
            'null' => [null],
            'blank' => ['   '],
            'text' => ['soon'],
            'array' => [['2026-11-27']],
            'number' => [12345],
        ];
    }
}
