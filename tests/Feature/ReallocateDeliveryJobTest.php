<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\MileageReadingKind;
use App\Models\DeliveryJob;
use App\Models\DeliveryRequest;
use App\Models\MileageReading;
use App\Models\OperationalShift;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInVolunteers;
use Tests\TestCase;

/**
 * Allocate stays available on an Allocated job, so the rider can be changed until the job is collected.
 */
class ReallocateDeliveryJobTest extends TestCase
{
    use RefreshDatabase, SignsInVolunteers;

    private const string ALLOCATED_AT = '2026-11-20 12:00:00';

    private const string REQUEST_AT = '2026-11-20 13:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useThreeRingsConfig();
        // Fixtures are created at ALLOCATED_AT; signing in moves the clock on, so any write shows as a change.
        Carbon::setTestNow(self::ALLOCATED_AT);
    }

    public function test_an_allocated_job_can_be_allocated_to_another_active_shift(): void
    {
        [$job, $first] = $this->allocatedJob('Alex Morgan');
        $second = $this->shiftFor('Sam Taylor', 100002);
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $second->id])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Allocated->value)
            ->assertJsonPath('allocatedRider.id', '100002')
            ->assertJsonPath('allocatedRider.name', 'Sam Taylor')
            ->assertJsonPath('allocatedRider.shiftId', $second->id)
            ->assertJsonPath('allowedActions', ['allocate', 'collect', 'cancel']);

        $fresh = $job->fresh();
        $this->assertSame($second->id, $fresh->operational_shift_id);
        $this->assertSame(JobStatus::Allocated, $fresh->status);
        $this->assertNotSame($first->id, $fresh->operational_shift_id);
    }

    public function test_allocated_at_is_the_time_of_the_latest_allocation(): void
    {
        [$job] = $this->allocatedJob();
        $second = $this->shiftFor('Sam Taylor', 100002);
        $this->signInAs('Controller');

        $response = $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $second->id])
            ->assertOk();

        $this->assertSame(Carbon::now()->toIso8601String(), $response->json('allocatedRider.allocatedAt'));
        $this->assertTrue($job->fresh()->allocated_at->equalTo(Carbon::now()));
        $this->assertTrue($job->fresh()->allocated_at->greaterThan(Carbon::parse(self::ALLOCATED_AT)));
    }

    public function test_a_job_can_be_re_allocated_more_than_once(): void
    {
        [$job, $first] = $this->allocatedJob();
        $second = $this->shiftFor('Sam Taylor', 100002);
        $this->signInAs('Controller');

        foreach ([$second, $first, $second] as $shift) {
            $this->withToken('valid-token')
                ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
                ->assertOk()
                ->assertJsonPath('allocatedRider.shiftId', $shift->id);
        }

        $this->assertSame($second->id, $job->fresh()->operational_shift_id);
    }

    public function test_allocating_to_the_same_shift_again_is_a_no_op(): void
    {
        [$job, $shift] = $this->allocatedJob('Alex Morgan');
        $updatedAt = $job->fresh()->updated_at;
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Allocated->value)
            ->assertJsonPath('allocatedRider.shiftId', $shift->id)
            ->assertJsonPath('allocatedRider.name', 'Alex Morgan')
            ->assertJsonPath('allowedActions', ['allocate', 'collect', 'cancel']);

        $fresh = $job->fresh();
        $this->assertTrue($fresh->updated_at->equalTo($updatedAt), 'updated_at must not move');
        $this->assertTrue($fresh->allocated_at->equalTo(Carbon::parse(self::ALLOCATED_AT)), 'allocated_at must not move');
    }

    public function test_the_same_shift_is_a_no_op_even_after_that_shift_logged_off(): void
    {
        [$job, $shift] = $this->allocatedJob();
        $startMileage = $shift->startMileage() ?? 0;
        $shift->update(['logged_off_at' => now()]);
        MileageReading::query()->create([
            'operational_shift_id' => $shift->id,
            'kind' => MileageReadingKind::End,
            'mileage' => $startMileage + 5,
            'reason' => null,
            'recorded_at' => now(),
            'recorded_by_user_id' => $shift->logged_on_by_user_id,
        ]);
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertOk()
            ->assertJsonPath('allocatedRider.shiftId', $shift->id);

        $this->assertSame($shift->id, $job->fresh()->operational_shift_id);
    }

    public function test_re_allocating_to_a_logged_off_shift_is_rejected_and_keeps_the_rider(): void
    {
        [$job, $shift] = $this->allocatedJob();
        $updatedAt = $job->fresh()->updated_at;
        $loggedOff = OperationalShift::factory()->loggedOff()->create();
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $loggedOff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shiftId']);

        $this->assertUnchanged($job, $shift, $updatedAt);
    }

    public function test_re_allocating_to_an_unknown_shift_is_rejected_and_keeps_the_rider(): void
    {
        [$job, $shift] = $this->allocatedJob();
        $updatedAt = $job->fresh()->updated_at;
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shiftId']);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shiftId']);

        $this->assertUnchanged($job, $shift, $updatedAt);
    }

    #[DataProvider('statusesThatCannotBeReallocated')]
    public function test_a_job_that_is_collected_or_finished_cannot_be_re_allocated(JobStatus $status): void
    {
        $shift = $this->shiftFor('Alex Morgan', 100001);
        $job = DeliveryJob::factory()->create([
            'status' => $status,
            'operational_shift_id' => $shift->id,
            'allocated_at' => self::ALLOCATED_AT,
        ]);
        $updatedAt = $job->fresh()->updated_at;
        $other = $this->shiftFor('Sam Taylor', 100002);
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);

        // Even the shift it already has: only New and Allocated jobs accept Allocate.
        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);

        $this->assertUnchanged($job, $shift, $updatedAt, $status);
    }

    /**
     * @return array<string, array{JobStatus}>
     */
    public static function statusesThatCannotBeReallocated(): array
    {
        return [
            'collected' => [JobStatus::Collected],
            'delivered' => [JobStatus::Delivered],
            'cancelled' => [JobStatus::Cancelled],
        ];
    }

    public function test_allowed_actions_include_allocate_for_new_and_allocated_jobs_only(): void
    {
        $new = DeliveryJob::factory()->create();
        [$allocated] = $this->allocatedJob();
        $collected = DeliveryJob::factory()->collected()->create();
        $delivered = DeliveryJob::factory()->delivered()->create();
        $cancelled = DeliveryJob::factory()->cancelled()->create();
        $this->signInAs('Controller');

        $this->withToken('valid-token')->getJson("/api/jobs/{$new->id}")
            ->assertJsonPath('allowedActions', ['allocate', 'cancel', 'relay']);
        $this->withToken('valid-token')->getJson("/api/jobs/{$allocated->id}")
            ->assertJsonPath('allowedActions', ['allocate', 'collect', 'cancel']);
        $this->withToken('valid-token')->getJson("/api/jobs/{$collected->id}")
            ->assertJsonPath('allowedActions', ['deliver', 'cancel']);
        $this->withToken('valid-token')->getJson("/api/jobs/{$delivered->id}")
            ->assertJsonPath('allowedActions', []);
        $this->withToken('valid-token')->getJson("/api/jobs/{$cancelled->id}")
            ->assertJsonPath('allowedActions', []);

        $active = collect($this->withToken('valid-token')->getJson('/api/jobs/active')->assertOk()->json('data'))
            ->keyBy('id');
        $this->assertSame(['allocate', 'collect', 'cancel'], $active[$allocated->id]['allowedActions']);
    }

    public function test_an_admin_can_re_allocate(): void
    {
        [$job] = $this->allocatedJob();
        $second = $this->shiftFor('Sam Taylor', 100002);

        User::factory()->admin()->create(['email' => 'rider@bloodbikes.wales', 'google_id' => null]);
        Http::fake(['www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []])]);
        $this->mockVerifiedClaims();
        Carbon::setTestNow(self::REQUEST_AT);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $second->id])
            ->assertOk()
            ->assertJsonPath('allocatedRider.shiftId', $second->id);
    }

    #[DataProvider('rolesThatCannotAllocate')]
    public function test_roles_that_cannot_manage_jobs_cannot_re_allocate(string $role): void
    {
        [$job, $shift] = $this->allocatedJob();
        $updatedAt = $job->fresh()->updated_at;
        $second = $this->shiftFor('Sam Taylor', 100002);
        $this->signInAs($role);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $second->id])
            ->assertForbidden();

        $this->assertUnchanged($job, $shift, $updatedAt);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesThatCannotAllocate(): array
    {
        return [
            'rider' => ['Rider'],
            'driver' => ['Driver'],
            'trustee' => ['Trustee'],
        ];
    }

    public function test_a_relay_leg_can_be_re_allocated_and_the_parent_status_is_unaffected(): void
    {
        $firstShift = $this->shiftFor('Alex Morgan', 100001);
        $secondShift = $this->shiftFor('Sam Taylor', 100002);
        $thirdShift = $this->shiftFor('Jo Evans', 100003);
        [$parent, $legs] = $this->relayWithAllocatedLegs([$firstShift, $secondShift]);
        $otherLegUpdatedAt = $legs[1]->fresh()->updated_at;
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[0]->id}/actions/allocate", ['shiftId' => $thirdShift->id])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Allocated->value)
            ->assertJsonPath('allocatedRider.shiftId', $thirdShift->id)
            ->assertJsonPath('allocatedRider.name', 'Jo Evans')
            ->assertJsonPath('allowedActions', ['allocate', 'collect', 'cancel']);

        $this->assertSame($thirdShift->id, $legs[0]->fresh()->operational_shift_id);
        // The sibling keeps its own rider; the parent is still rolled up as Allocated.
        $this->assertSame($secondShift->id, $legs[1]->fresh()->operational_shift_id);
        $this->assertTrue($legs[1]->fresh()->updated_at->equalTo($otherLegUpdatedAt));
        $this->assertSame(JobStatus::Allocated, $parent->fresh()->status);
        $this->assertNull($parent->fresh()->operational_shift_id);

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.status', JobStatus::Allocated->value)
            ->assertJsonPath('data.0.legs.0.allocatedRider.name', 'Jo Evans')
            ->assertJsonPath('data.0.legs.1.allocatedRider.name', 'Sam Taylor');
    }

    public function test_re_allocating_a_leg_with_the_same_shift_does_not_touch_the_parent(): void
    {
        $firstShift = $this->shiftFor('Alex Morgan', 100001);
        $secondShift = $this->shiftFor('Sam Taylor', 100002);
        [$parent, $legs] = $this->relayWithAllocatedLegs([$firstShift, $secondShift]);
        $legUpdatedAt = $legs[0]->fresh()->updated_at;
        $parentUpdatedAt = $parent->fresh()->updated_at;
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[0]->id}/actions/allocate", ['shiftId' => $firstShift->id])
            ->assertOk();

        $this->assertTrue($legs[0]->fresh()->updated_at->equalTo($legUpdatedAt));
        $this->assertTrue($parent->fresh()->updated_at->equalTo($parentUpdatedAt));
    }

    public function test_a_failed_leg_re_allocation_keeps_the_leg_rider_and_the_parent(): void
    {
        $firstShift = $this->shiftFor('Alex Morgan', 100001);
        $secondShift = $this->shiftFor('Sam Taylor', 100002);
        [$parent, $legs] = $this->relayWithAllocatedLegs([$firstShift, $secondShift]);
        $loggedOff = OperationalShift::factory()->loggedOff()->create();
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[0]->id}/actions/allocate", ['shiftId' => $loggedOff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shiftId']);

        $this->assertSame($firstShift->id, $legs[0]->fresh()->operational_shift_id);
        $this->assertSame(JobStatus::Allocated, $parent->fresh()->status);
    }

    public function test_a_collected_relay_leg_cannot_be_re_allocated(): void
    {
        $shift = $this->shiftFor('Alex Morgan', 100001);
        $parent = DeliveryJob::factory()->create(['status' => JobStatus::Collected]);
        $leg = DeliveryJob::factory()->create([
            'status' => JobStatus::Collected,
            'parent_job_id' => $parent->id,
            'leg_number' => 1,
            'operational_shift_id' => $shift->id,
        ]);
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$leg->id}/actions/allocate", ['shiftId' => $this->shiftFor('Sam Taylor', 100002)->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);

        $this->assertSame($shift->id, $leg->fresh()->operational_shift_id);
    }

    public function test_a_relay_parent_still_cannot_be_allocated_even_once_allocated(): void
    {
        [$parent] = $this->relayWithAllocatedLegs([
            $this->shiftFor('Alex Morgan', 100001),
            $this->shiftFor('Sam Taylor', 100002),
        ]);
        $parentUpdatedAt = $parent->fresh()->updated_at;
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$parent->id}/actions/allocate", ['shiftId' => $this->shiftFor('Jo Evans', 100003)->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);

        $this->assertNull($parent->fresh()->operational_shift_id);
        $this->assertTrue($parent->fresh()->updated_at->equalTo($parentUpdatedAt));

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$parent->id}")
            ->assertJsonPath('allowedActions', ['cancel']);
    }

    public function test_allocating_a_new_job_is_unchanged(): void
    {
        $job = DeliveryJob::factory()->create();
        $shift = $this->shiftFor('Alex Morgan', 100001);
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Allocated->value)
            ->assertJsonPath('allocatedRider.shiftId', $shift->id)
            ->assertJsonPath('allocatedRider.allocatedAt', Carbon::now()->toIso8601String());

        $fresh = $job->fresh();
        $this->assertSame(JobStatus::Allocated, $fresh->status);
        $this->assertSame($shift->id, $fresh->operational_shift_id);
        $this->assertTrue($fresh->allocated_at->equalTo(Carbon::now()));
    }

    public function test_a_re_allocated_job_can_still_be_collected_and_keeps_the_new_rider(): void
    {
        [$job] = $this->allocatedJob();
        $second = $this->shiftFor('Sam Taylor', 100002);
        $this->signInAs('Controller');

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $second->id])
            ->assertOk();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/collect", [
                'contentsConfirmed' => true,
                'receiptNumber' => 'RCP-1',
                'collectedAt' => '2026-11-20T13:30:00+00:00',
            ])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Collected->value)
            ->assertJsonPath('allocatedRider.shiftId', $second->id)
            ->assertJsonPath('allowedActions', ['deliver', 'cancel']);
    }

    /**
     * An Allocated job (allocated at ALLOCATED_AT) on a fresh active shift for the given rider.
     *
     * @return array{0: DeliveryJob, 1: OperationalShift}
     */
    private function allocatedJob(string $riderName = 'Alex Morgan', int $externalId = 100001): array
    {
        $shift = $this->shiftFor($riderName, $externalId);

        $job = DeliveryJob::factory()->allocated()->create([
            'operational_shift_id' => $shift->id,
            'allocated_at' => self::ALLOCATED_AT,
        ]);

        return [$job, $shift];
    }

    private function shiftFor(string $riderName, int $externalId): OperationalShift
    {
        $rider = Rider::factory()->create(['external_id' => $externalId, 'name' => $riderName]);

        return OperationalShift::factory()->create(['rider_id' => $rider->id]);
    }

    /**
     * A relay whose legs are all Allocated, one per shift, under a request rolled up as Allocated.
     *
     * @param  list<OperationalShift>  $shifts
     * @return array{0: DeliveryRequest, 1: list<DeliveryJob>}
     */
    private function relayWithAllocatedLegs(array $shifts): array
    {
        $seed = DeliveryJob::factory()->allocated()->create();
        $requestId = $seed->request_id;

        $legs = [];
        foreach ($shifts as $index => $shift) {
            $legs[] = DeliveryJob::factory()->allocated()->create([
                'request_id' => $requestId,
                'leg_number' => $index + 1,
                'operational_shift_id' => $shift->id,
                'allocated_at' => self::ALLOCATED_AT,
            ]);
        }

        $request = DeliveryRequest::query()->findOrFail($requestId);
        $request->update(['status' => JobStatus::Allocated]);

        return [$request->fresh(['legs']), $legs];
    }

    private function assertUnchanged(DeliveryJob $job, OperationalShift $shift, Carbon $updatedAt, JobStatus $status = JobStatus::Allocated): void
    {
        $fresh = $job->fresh();

        $this->assertSame($status, $fresh->status);
        $this->assertSame($shift->id, $fresh->operational_shift_id);
        $this->assertTrue($fresh->allocated_at->equalTo(Carbon::parse(self::ALLOCATED_AT)));
        $this->assertTrue($fresh->updated_at->equalTo($updatedAt));
    }

    private function signInAs(string $role): void
    {
        $this->fakeDirectory([$this->volunteer($role)]);
        $this->mockVerifiedClaims();
        Carbon::setTestNow(self::REQUEST_AT);
    }
}
