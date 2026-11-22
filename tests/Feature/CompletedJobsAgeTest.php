<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\DeliveryRequest;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInVolunteers;
use Tests\TestCase;

/**
 * GET /api/jobs/completed hides jobs completed more than 24 hours ago, per user setting.
 */
class CompletedJobsAgeTest extends TestCase
{
    use RefreshDatabase, SignsInVolunteers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useThreeRingsConfig();
        Carbon::setTestNow('2026-11-20 12:00:00');
    }

    public function test_jobs_delivered_more_than_a_day_ago_are_hidden_by_default(): void
    {
        $this->delivered('JB-1001', hoursAgo: 25);
        $this->delivered('JB-1002', hoursAgo: 1);

        $this->assertReferences(['JB-1002'], $this->completedAs());
    }

    public function test_every_completed_job_is_listed_when_the_setting_is_on(): void
    {
        $this->delivered('JB-1001', hoursAgo: 25);
        $this->delivered('JB-1002', hoursAgo: 1);
        $this->turnOnShowCompletedJobs();

        $this->assertReferences(['JB-1002', 'JB-1001'], $this->completedAs(), sort: true);
    }

    public function test_an_overnight_delivery_is_listed_even_though_its_claimed_time_is_old(): void
    {
        // The SPA sends the job's created day plus the typed time: created Monday, delivered
        // Tuesday 02:00, it claims Monday 02:00 (30 hours ago) but was recorded just now.
        $this->delivered('JB-1001', hoursAgo: 30, updatedHoursAgo: 0);
        $this->delivered('JB-1002', hoursAgo: 72, updatedHoursAgo: 0);

        $this->assertReferences(['JB-1001', 'JB-1002'], $this->completedAs(), sort: true);
    }

    public function test_a_job_whose_claimed_and_recorded_times_are_both_old_is_hidden(): void
    {
        $this->delivered('JB-1001', hoursAgo: 48);
        $this->delivered('JB-1002', hoursAgo: 30, updatedHoursAgo: 26);

        $this->assertReferences([], $this->completedAs());
    }

    public function test_a_recently_claimed_delivery_is_listed_even_if_it_was_recorded_earlier(): void
    {
        // Claims 2 hours ago, row last touched long before (e.g. a backfill): the claimed time counts too.
        $this->delivered('JB-1001', hoursAgo: 2, updatedHoursAgo: 72);

        $this->assertReferences(['JB-1001'], $this->completedAs());
    }

    public function test_cancelled_jobs_use_cancelled_at_or_updated_at(): void
    {
        $this->cancelled('JB-1001', hoursAgo: 30);
        $this->cancelled('JB-1002', hoursAgo: 3);
        // Cancelled just now but claiming an old time, like the overnight delivery.
        $this->cancelled('JB-1003', hoursAgo: 30, updatedHoursAgo: 0);
        $this->cancelled('JB-1004', hoursAgo: 2, updatedHoursAgo: 72);

        $this->assertReferences(['JB-1002', 'JB-1003', 'JB-1004'], $this->completedAs(), sort: true);
    }

    public function test_only_the_claimed_time_that_matches_the_status_counts(): void
    {
        // A Delivered job's cancelled_at (and a Cancelled job's delivered_at) says nothing about when it finished.
        DeliveryJob::factory()->delivered()->create([
            'reference' => 'JB-1001',
            'delivered_at' => now()->subHours(48),
            'cancelled_at' => now()->subHours(1),
            'updated_at' => now()->subHours(48),
        ]);
        DeliveryJob::factory()->cancelled()->create([
            'reference' => 'JB-1002',
            'cancelled_at' => now()->subHours(48),
            'delivered_at' => now()->subHours(1),
            'updated_at' => now()->subHours(48),
        ]);

        $this->assertReferences([], $this->completedAs());
    }

    public function test_a_job_without_its_timestamp_falls_back_to_updated_at(): void
    {
        DeliveryJob::factory()->delivered()->create(['reference' => 'JB-1001', 'delivered_at' => null, 'updated_at' => now()->subHours(26)]);
        DeliveryJob::factory()->delivered()->create(['reference' => 'JB-1002', 'delivered_at' => null, 'updated_at' => now()->subHours(2)]);
        DeliveryJob::factory()->cancelled()->create(['reference' => 'JB-1003', 'cancelled_at' => null, 'updated_at' => now()->subHours(26)]);
        DeliveryJob::factory()->cancelled()->create(['reference' => 'JB-1004', 'cancelled_at' => null, 'updated_at' => now()->subHours(2)]);

        $this->assertReferences(['JB-1002', 'JB-1004'], $this->completedAs(), sort: true);
    }

    public function test_the_boundary_is_exactly_twenty_four_hours_on_either_time(): void
    {
        $day = 24;
        $justOver = fn () => now()->subHours($day)->subSecond();

        // Claimed time exactly 24h old is in, one second older is out (updated_at old in both).
        $this->delivered('JB-1001', hoursAgo: $day, updatedHoursAgo: 48);
        DeliveryJob::factory()->delivered()->create(['reference' => 'JB-1002', 'delivered_at' => $justOver(), 'updated_at' => now()->subHours(48)]);
        // Same for updated_at (claimed time old in both).
        $this->delivered('JB-1003', hoursAgo: 48, updatedHoursAgo: $day);
        DeliveryJob::factory()->delivered()->create(['reference' => 'JB-1004', 'delivered_at' => now()->subHours(48), 'updated_at' => $justOver()]);
        // And for cancelled jobs.
        $this->cancelled('JB-1005', hoursAgo: $day, updatedHoursAgo: 48);
        DeliveryJob::factory()->cancelled()->create(['reference' => 'JB-1006', 'cancelled_at' => $justOver(), 'updated_at' => now()->subHours(48)]);
        $this->cancelled('JB-1007', hoursAgo: 48, updatedHoursAgo: $day);
        DeliveryJob::factory()->cancelled()->create(['reference' => 'JB-1008', 'cancelled_at' => now()->subHours(48), 'updated_at' => $justOver()]);

        $this->assertReferences(['JB-1001', 'JB-1003', 'JB-1005', 'JB-1007'], $this->completedAs(), sort: true);
    }

    public function test_the_setting_on_lists_everything_including_old_claims_and_old_records(): void
    {
        $this->delivered('JB-1001', hoursAgo: 48);
        $this->delivered('JB-1002', hoursAgo: 30, updatedHoursAgo: 0);
        $this->cancelled('JB-1003', hoursAgo: 48);
        $this->cancelled('JB-1004', hoursAgo: 1);
        $this->turnOnShowCompletedJobs();

        $this->assertReferences(['JB-1001', 'JB-1002', 'JB-1003', 'JB-1004'], $this->completedAs(), sort: true);
    }

    public function test_a_delivery_recorded_overnight_through_the_api_shows_for_a_day_and_then_ages_out(): void
    {
        // Created Monday 22:00; on Tuesday 02:00 the SPA records the delivery as created day + typed time.
        Carbon::setTestNow('2026-11-16 22:00:00');
        $job = DeliveryJob::factory()->collected()->create(['reference' => 'JB-4001']);

        Carbon::setTestNow('2026-11-17 02:00:00');
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", [
                'recipient' => 'Ward 4',
                'deliveredAt' => '2026-11-16T02:00:00+00:00',
            ])
            ->assertOk();

        $this->assertReferences(['JB-4001'], $this->listCompleted());

        Carbon::setTestNow('2026-11-18 01:59:00');
        $this->assertReferences(['JB-4001'], $this->listCompleted());

        Carbon::setTestNow('2026-11-18 02:01:00');
        $this->assertReferences([], $this->listCompleted());
    }

    public function test_offset_timestamps_are_stored_as_utc(): void
    {
        $job = DeliveryJob::factory()->allocated()->create();

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/collect", [
                'contentsConfirmed' => true,
                'receiptNumber' => 'RCP-1',
                'collectedAt' => '2026-11-20T10:30:00+01:00',
            ])
            ->assertOk()
            ->assertJsonPath('collectionRecord.collectedAt', '2026-11-20T09:30:00+00:00');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", [
                'recipient' => 'Ward 4',
                'deliveredAt' => '2026-11-20T11:15:00+01:00',
            ])
            ->assertOk()
            ->assertJsonPath('deliveryRecord.deliveredAt', '2026-11-20T10:15:00+00:00');

        $this->assertSame('2026-11-20 09:30:00', DB::table('delivery_legs')->where('id', $job->id)->value('collected_at'));
        $this->assertSame('2026-11-20 10:15:00', DB::table('delivery_legs')->where('id', $job->id)->value('delivered_at'));
    }

    public function test_a_relay_is_filtered_as_one_job_by_its_own_completion_time(): void
    {
        $this->relay('JB-2001', JobStatus::Delivered, hoursAgo: 30);
        $this->relay('JB-2002', JobStatus::Delivered, hoursAgo: 2);
        $this->relay('JB-2003', JobStatus::Cancelled, hoursAgo: 30);
        $this->relay('JB-2004', JobStatus::Cancelled, hoursAgo: 2);

        $response = $this->completedAs();

        $this->assertReferences(['JB-2002', 'JB-2004'], $response, sort: true);
        // A listed relay still comes with all of its legs; legs are never listed on their own.
        $recent = collect($response->json('data'))->firstWhere('reference', 'JB-2002');
        $this->assertSame(['JB-2002-L1', 'JB-2002-L2'], array_column($recent['legs'], 'reference'));

        $this->turnOnShowCompletedJobs();
        $this->assertReferences(['JB-2001', 'JB-2002', 'JB-2003', 'JB-2004'], $this->completedAs(), sort: true);
    }

    public function test_a_relay_with_a_delivered_leg_and_an_individually_cancelled_leg_is_listed_via_updated_at(): void
    {
        // Leg 1 was delivered long ago (claiming a time over a day old); leg 2 is cancelled just now, which
        // rolls the request up to Delivered. The request has neither delivered_at nor cancelled_at.
        $seed = DeliveryJob::factory()->collected()->create(['reference' => 'JB-2500', 'updated_at' => now()->subDays(2)]);
        $requestId = $seed->request_id;
        DeliveryJob::factory()->delivered()->create([
            'reference' => 'JB-2500-L1',
            'request_id' => $requestId,
            'leg_number' => 1,
            'delivered_at' => now()->subHours(30),
            'updated_at' => now()->subHours(30),
        ]);
        $second = DeliveryJob::factory()->collected()->create([
            'reference' => 'JB-2500-L2',
            'request_id' => $requestId,
            'leg_number' => 2,
        ]);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();
        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$second->id}/cancel", ['reason' => 'Rider unavailable'])
            ->assertOk();

        $request = DeliveryRequest::query()->findOrFail($requestId);
        $this->assertSame(JobStatus::Delivered, $request->status);

        $this->assertReferences(['JB-2500'], $this->listCompleted());

        // Once the recording is more than a day old it ages out like any other job.
        Carbon::setTestNow(now()->addHours(25));
        $this->assertReferences([], $this->listCompleted());
    }

    public function test_the_active_list_is_never_age_filtered(): void
    {
        DeliveryJob::factory()->create(['reference' => 'JB-3001', 'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
        DeliveryJob::factory()->allocated()->create(['reference' => 'JB-3002', 'updated_at' => now()->subDays(10)]);
        DeliveryJob::factory()->collected()->create(['reference' => 'JB-3003', 'updated_at' => now()->subDays(10)]);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_another_users_setting_does_not_change_my_list(): void
    {
        $this->delivered('JB-1001', hoursAgo: 25);
        $this->delivered('JB-1002', hoursAgo: 1);

        $other = User::factory()->create(['email' => 'other@bloodbikes.wales']);
        UserSetting::query()->create(['user_id' => $other->id, 'show_completed_jobs' => true]);

        $this->assertReferences(['JB-1002'], $this->completedAs());
    }

    public function test_turning_the_setting_on_through_the_api_changes_the_list(): void
    {
        $this->delivered('JB-1001', hoursAgo: 25);
        $this->delivered('JB-1002', hoursAgo: 1);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/jobs/completed')->assertJsonCount(1, 'data');
        $this->withToken('valid-token')->patchJson('/api/me', ['showCompletedJobs' => true])->assertOk();
        $this->withToken('valid-token')->getJson('/api/jobs/completed')->assertJsonCount(2, 'data');
        $this->withToken('valid-token')->patchJson('/api/me', ['showCompletedJobs' => false])->assertOk();
        $this->withToken('valid-token')->getJson('/api/jobs/completed')->assertJsonCount(1, 'data');
    }

    private function delivered(string $reference, int $hoursAgo, ?int $updatedHoursAgo = null): DeliveryJob
    {
        return DeliveryJob::factory()->delivered()->create([
            'reference' => $reference,
            'delivered_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($updatedHoursAgo ?? $hoursAgo),
        ]);
    }

    private function cancelled(string $reference, int $hoursAgo, ?int $updatedHoursAgo = null): DeliveryJob
    {
        return DeliveryJob::factory()->cancelled()->create([
            'reference' => $reference,
            'cancelled_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($updatedHoursAgo ?? $hoursAgo),
        ]);
    }

    /**
     * A relay parent as the service leaves it: a Delivered parent has no delivered_at (its status
     * is rolled up from its legs), a Cancelled one has cancelled_at. Its legs finished at the same time.
     */
    private function relay(string $reference, JobStatus $status, int $hoursAgo): DeliveryJob
    {
        $at = now()->subHours($hoursAgo);
        $completion = $status === JobStatus::Delivered ? 'delivered_at' : 'cancelled_at';

        $seed = DeliveryJob::factory()->create([
            'reference' => $reference,
            'status' => $status,
            'updated_at' => $at,
        ]);
        $requestId = $seed->request_id;

        foreach ([1, 2] as $number) {
            DeliveryJob::factory()->create([
                'reference' => "{$reference}-L{$number}",
                'status' => $status,
                'request_id' => $requestId,
                'leg_number' => $number,
                $completion => $at,
                'updated_at' => $at,
            ]);
        }

        DeliveryRequest::withoutTimestamps(function () use ($requestId, $status, $at): void {
            DeliveryRequest::query()->whereKey($requestId)->update([
                'status' => $status->value,
                'updated_at' => $at,
            ]);
        });

        // Standalone id still resolves the request after the sole shared-id hop is replaced.
        return DeliveryJob::query()->where('request_id', $requestId)->orderBy('leg_number')->firstOrFail();
    }

    private function turnOnShowCompletedJobs(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'rider@bloodbikes.wales'],
            ['name' => 'Test Rider', 'google_id' => 'google-sub-123'],
        );
        UserSetting::query()->updateOrCreate(['user_id' => $user->id], ['show_completed_jobs' => true]);
    }

    private function completedAs(string $role = 'Controller'): TestResponse
    {
        $this->fakeDirectory([$this->volunteer($role)]);
        $this->mockVerifiedClaims();

        return $this->listCompleted();
    }

    private function listCompleted(): TestResponse
    {
        return $this->withToken('valid-token')
            ->getJson('/api/jobs/completed')
            ->assertOk();
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertReferences(array $expected, TestResponse $response, bool $sort = false): void
    {
        $actual = array_column($response->json('data'), 'reference');
        if ($sort) {
            sort($actual);
            sort($expected);
        }

        $this->assertSame($expected, $actual);
    }
}
