<?php

namespace App\Services\Jobs;

use App\Enums\JobAction;
use App\Enums\JobScope;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\DeliveryJob;
use App\Models\OperationalShift;
use App\Models\Place;
use App\Models\ServiceArea;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class DeliveryJobService
{
    public function __construct(private readonly RelayJobStatusAggregator $relayStatus) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $payload): DeliveryJob {
            $sender = $payload['sender'];
            $collection = $payload['collection'];
            $delivery = $payload['delivery'];

            $collectionPlace = $this->upsertPlace($collection);
            $deliveryPlace = $this->upsertPlace($delivery);

            $jobType = JobType::tryFrom((string) ($payload['jobType'] ?? '')) ?? JobType::Standard;
            // Only milk jobs carry a mother's contact; the request rejects it for any other type.
            $mother = $jobType === JobType::Milk ? ($payload['mother'] ?? null) : null;

            $job = DeliveryJob::query()->create([
                'reference' => $this->nextReference(),
                'status' => JobStatus::New,
                'job_type' => $jobType,
                'milk_litres' => $jobType === JobType::Milk ? $payload['milkLitres'] : null,
                'is_bereavement' => $jobType === JobType::Milk && filter_var($payload['isBereavement'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sender_name' => $sender['name'],
                'sender_phone' => $sender['phone'],
                'sender_organisation' => $this->normaliseOptionalText($sender['organisation'] ?? null),
                'mother_name' => $mother === null ? null : $this->normaliseOptionalText($mother['name'] ?? null),
                'mother_phone' => $mother === null ? null : $this->normaliseOptionalText($mother['phone'] ?? null),
                'mother_phone_secondary' => $mother === null ? null : $this->normaliseOptionalText($mother['secondaryPhone'] ?? null),
                'collection_place_id' => $collectionPlace->id,
                'delivery_place_id' => $deliveryPlace->id,
                'collection_notes' => $this->normaliseOptionalText($collection['notes'] ?? null),
                'delivery_notes' => $this->normaliseOptionalText($delivery['notes'] ?? null),
                'estimated_collection_at' => $this->parseTimestamp($payload['estimatedCollectionAt'] ?? null)?->utc(),
                'estimated_delivery_at' => $this->parseTimestamp($payload['estimatedDeliveryAt'] ?? null)?->utc(),
                'contents' => $payload['contents'],
                'created_by_user_id' => $actor->id,
            ]);

            $job->serviceAreas()->sync($this->serviceAreaIds(array_values($payload['serviceAreas'])));

            Log::info('Delivery job created.', [
                'job_id' => $job->id,
                'reference' => $job->reference,
                'user_id' => $actor->id,
            ]);

            return $job->load(['collectionPlace', 'deliveryPlace', 'serviceAreas']);
        });
    }

    /**
     * @return Collection<int, DeliveryJob>
     */
    public function listByScope(JobScope $scope): Collection
    {
        return DeliveryJob::query()
            ->topLevel()
            ->whereIn('status', $scope->statuses())
            ->with([
                'legs.collectionPlace',
                'legs.deliveryPlace',
                'legs.serviceAreas',
                'legs.operationalShift.rider',
                'collectionPlace',
                'deliveryPlace',
                'serviceAreas',
                'operationalShift.rider',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->each(static fn (DeliveryJob $job): DeliveryJob => $job->linkLegsToParent());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function relay(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertRelayConversionAllowed($locked);

            $routePoints = $this->routePoints($locked, $payload['rendezvousPoints']);
            $this->createRelayLegs($actor, $locked, $routePoints);

            Log::info('Delivery job converted to a relay.', [
                'job_id' => $locked->id,
                'leg_count' => count($routePoints) - 1,
                'user_id' => $actor->id,
            ]);

            return $locked->refresh()->load([
                'legs.collectionPlace',
                'legs.deliveryPlace',
                'legs.serviceAreas',
                'collectionPlace',
                'deliveryPlace',
                'serviceAreas',
            ])->linkLegsToParent();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function allocate(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertDirectLifecycleAllowed($locked);
            $this->assertActionAllowed($locked, JobAction::Allocate);

            $shift = $this->lockActiveShift($payload['shiftId']);

            $locked->fill([
                'status' => JobStatus::Allocated,
                'operational_shift_id' => $shift->id,
                'allocated_at' => now(),
            ])->save();

            $updated = $locked->refresh()->load(['operationalShift.rider', 'collectionPlace', 'deliveryPlace', 'serviceAreas']);
            $this->loadSiblingLegs($updated);
            $this->syncRelayParentFromLeg($updated);

            Log::info('Delivery job allocated.', [
                'job_id' => $updated->id,
                'shift_id' => $shift->id,
                'rider_id' => $shift->rider_id,
                'user_id' => $actor->id,
            ]);

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function collect(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertDirectLifecycleAllowed($locked);
            $this->assertActionAllowed($locked, JobAction::Collect);
            $this->assertPreviousLegDelivered($locked, JobAction::Collect);

            $locked->fill([
                'status' => JobStatus::Collected,
                'contents_confirmed' => (bool) $payload['contentsConfirmed'],
                'receipt_number' => $payload['receiptNumber'],
                'collected_at' => $this->parseTimestamp($payload['collectedAt'] ?? null) ?? now(),
            ])->save();

            $updated = $locked->refresh()->load(['collectionPlace', 'deliveryPlace', 'serviceAreas', 'operationalShift.rider']);
            $this->loadSiblingLegs($updated);
            $this->syncRelayParentFromLeg($updated);

            Log::info('Delivery job collected.', [
                'job_id' => $updated->id,
                'user_id' => $actor->id,
            ]);

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliver(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertDirectLifecycleAllowed($locked);
            $this->assertActionAllowed($locked, JobAction::Deliver);
            $this->assertPreviousLegDelivered($locked, JobAction::Deliver);

            $noRecipient = (bool) ($payload['noRecipient'] ?? false);

            $locked->fill([
                'status' => JobStatus::Delivered,
                'no_recipient' => $noRecipient,
                'recipient' => $noRecipient ? null : $payload['recipient'],
                'delivered_at' => $this->parseTimestamp($payload['deliveredAt'] ?? null) ?? now(),
            ])->save();

            $updated = $locked->refresh()->load(['collectionPlace', 'deliveryPlace', 'serviceAreas', 'operationalShift.rider']);
            $this->loadSiblingLegs($updated);
            $this->syncRelayParentFromLeg($updated);

            Log::info('Delivery job delivered.', [
                'job_id' => $updated->id,
                'user_id' => $actor->id,
            ]);

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancel(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);

            if ($locked->isRelayParent()) {
                return $this->cancelRelayParent($actor, $locked, $payload);
            }

            $this->assertActionAllowed($locked, JobAction::Cancel);

            $locked->fill([
                'status' => JobStatus::Cancelled,
                'cancellation_reason' => $this->normaliseOptionalText($payload['reason'] ?? null),
                'cancelled_at' => now(),
            ])->save();

            $updated = $locked->refresh()->load(['collectionPlace', 'deliveryPlace', 'serviceAreas', 'operationalShift.rider']);
            $this->syncRelayParentFromLeg($updated);

            Log::info('Delivery job cancelled.', [
                'job_id' => $updated->id,
                'user_id' => $actor->id,
            ]);

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function upsertPlace(array $location): Place
    {
        $place = Place::query()->where('external_id', $location['placeId'])->first();
        $postcode = $location['postcode'] ?? null;

        if ($place === null) {
            return Place::query()->create([
                'external_id' => $location['placeId'],
                'address' => $location['address'],
                'postcode' => $postcode,
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ]);
        }

        $place->fill([
            'address' => $location['address'],
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
        ]);

        // A client that does not send a postcode (older SPA, or a place Google has none for) must not erase one we already hold.
        if ($postcode !== null) {
            $place->postcode = $postcode;
        }

        $place->save();

        return $place;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function serviceAreaIds(array $codes): array
    {
        $normalized = array_map(
            static fn (string $code): string => strtoupper($code),
            $codes,
        );

        return ServiceArea::query()
            ->whereIn('code', $normalized)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rendezvousPoints
     * @return list<array<string, mixed>>
     */
    private function routePoints(DeliveryJob $job, array $rendezvousPoints): array
    {
        $job->loadMissing(['collectionPlace', 'deliveryPlace']);

        return [
            [
                'placeId' => $job->collectionPlace->external_id,
                'address' => $job->collectionPlace->address,
                'postcode' => $job->collectionPlace->postcode,
                'latitude' => $job->collectionPlace->latitude,
                'longitude' => $job->collectionPlace->longitude,
            ],
            ...$rendezvousPoints,
            [
                'placeId' => $job->deliveryPlace->external_id,
                'address' => $job->deliveryPlace->address,
                'postcode' => $job->deliveryPlace->postcode,
                'latitude' => $job->deliveryPlace->latitude,
                'longitude' => $job->deliveryPlace->longitude,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $routePoints
     */
    private function createRelayLegs(User $actor, DeliveryJob $parent, array $routePoints): void
    {
        $parent->loadMissing('serviceAreas');
        $serviceAreaIds = $parent->serviceAreas->pluck('id')->all();
        $legCount = count($routePoints) - 1;

        for ($index = 0; $index < $legCount; $index++) {
            $collection = $routePoints[$index];
            $delivery = $routePoints[$index + 1];

            $collectionPlace = $this->upsertPlace($collection);
            $deliveryPlace = $this->upsertPlace($delivery);

            $leg = DeliveryJob::query()->create([
                'reference' => sprintf('%s-L%d', $parent->reference, $index + 1),
                'status' => JobStatus::New,
                'parent_job_id' => $parent->id,
                'leg_number' => $index + 1,
                'job_type' => $parent->job_type,
                'milk_litres' => $parent->milk_litres,
                'is_bereavement' => $parent->is_bereavement,
                'sender_name' => $parent->sender_name,
                'sender_phone' => $parent->sender_phone,
                'sender_organisation' => $parent->sender_organisation,
                'mother_name' => $parent->mother_name,
                'mother_phone' => $parent->mother_phone,
                'mother_phone_secondary' => $parent->mother_phone_secondary,
                'collection_place_id' => $collectionPlace->id,
                'delivery_place_id' => $deliveryPlace->id,
                // Only rendezvous points carry arrivesAt, so the last leg (to the final delivery) stays null.
                'planned_arrival_at' => $this->parseTimestamp($delivery['arrivesAt'] ?? null)?->utc(),
                'contents' => $parent->contents,
                'created_by_user_id' => $actor->id,
            ]);

            $leg->serviceAreas()->sync($serviceAreaIds);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancelRelayParent(User $actor, DeliveryJob $parent, array $payload): DeliveryJob
    {
        $reason = $this->normaliseOptionalText($payload['reason'] ?? null);
        $cancelledAt = now();

        $legs = DeliveryJob::query()
            ->where('parent_job_id', $parent->id)
            ->lockForUpdate()
            ->get();

        foreach ($legs as $leg) {
            if ($leg->status === JobStatus::Delivered || $leg->status === JobStatus::Cancelled) {
                continue;
            }

            $leg->fill([
                'status' => JobStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_at' => $cancelledAt,
            ])->save();
        }

        $parent->fill([
            'status' => JobStatus::Cancelled,
            'cancellation_reason' => $reason,
            'cancelled_at' => $cancelledAt,
        ])->save();

        Log::info('Relay delivery job cancelled.', [
            'job_id' => $parent->id,
            'user_id' => $actor->id,
        ]);

        return $parent->refresh()->load([
            'legs.collectionPlace',
            'legs.deliveryPlace',
            'legs.serviceAreas',
            'collectionPlace',
            'deliveryPlace',
            'serviceAreas',
        ])->linkLegsToParent();
    }

    private function syncRelayParentFromLeg(DeliveryJob $leg): void
    {
        if ($leg->parent_job_id === null) {
            return;
        }

        /** @var DeliveryJob|null $parent */
        $parent = DeliveryJob::query()
            ->whereKey($leg->parent_job_id)
            ->lockForUpdate()
            ->first();

        if ($parent === null || ! $parent->isRelayParent()) {
            return;
        }

        $legs = DeliveryJob::query()
            ->where('parent_job_id', $parent->id)
            ->orderBy('leg_number')
            ->get();

        $parent->status = $this->relayStatus->aggregate($legs);
        $parent->save();
    }

    private function assertRelayConversionAllowed(DeliveryJob $job): void
    {
        if ($job->isRelayParent()) {
            throw ValidationException::withMessages([
                'job' => ['This job is already a relay.'],
            ]);
        }

        if ($job->parent_job_id !== null) {
            throw ValidationException::withMessages([
                'job' => ['Relay legs cannot be converted again.'],
            ]);
        }

        if ($job->status !== JobStatus::New) {
            throw ValidationException::withMessages([
                'job' => ['Only a new job can be converted to a relay.'],
            ]);
        }
    }

    private function assertDirectLifecycleAllowed(DeliveryJob $job): void
    {
        if (! $job->isRelayParent()) {
            return;
        }

        throw ValidationException::withMessages([
            'job' => ['Manage relay jobs through their individual legs.'],
        ]);
    }

    private function lockJob(string $jobId): DeliveryJob
    {
        /** @var DeliveryJob|null $locked */
        $locked = DeliveryJob::query()
            ->with(['collectionPlace', 'deliveryPlace', 'serviceAreas', 'legs'])
            ->whereKey($jobId)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'job' => ['The selected job is invalid.'],
            ]);
        }

        // Read inside the transaction, after the lock, so the previous-leg check never sees a copy
        // loaded before the request started (route model binding).
        $this->loadSiblingLegs($locked);

        return $locked;
    }

    /**
     * Eager load the siblings of a relay leg in one go so allowed actions and waitingForLeg never query per leg.
     */
    private function loadSiblingLegs(DeliveryJob $job): void
    {
        if ($job->parent_job_id !== null) {
            $job->load('parentJob.legs');
        }
    }

    /**
     * Leg N (N > 1) can only be collected or delivered once leg N-1 is Delivered. Allocating is not
     * checked, so riders can be lined up in advance.
     *
     * No lock on the previous leg is needed. This runs inside the transaction on a fresh read, and
     * Delivered is terminal (no action leaves it, and cancelling a relay skips Delivered legs), so
     * a leg that reads Delivered stays Delivered. If leg N-1 is being delivered concurrently and
     * has not committed yet we read its old status and reject, which is the same outcome as this
     * request having arrived first; the caller can simply retry. Leg N therefore cannot complete
     * out of order.
     */
    private function assertPreviousLegDelivered(DeliveryJob $leg, JobAction $action): void
    {
        $waitingFor = $leg->waitingForLeg();

        if ($waitingFor === null) {
            return;
        }

        throw ValidationException::withMessages([
            'action' => [
                sprintf(
                    'Leg %d must be delivered before leg %d can be %s.',
                    $waitingFor,
                    $leg->leg_number,
                    $action === JobAction::Collect ? 'collected' : 'delivered',
                ),
            ],
        ]);
    }

    private function lockActiveShift(string $shiftId): OperationalShift
    {
        /** @var OperationalShift|null $shift */
        $shift = OperationalShift::query()
            ->with('rider')
            ->whereKey($shiftId)
            ->lockForUpdate()
            ->first();

        if ($shift === null) {
            throw ValidationException::withMessages([
                'shiftId' => ['The selected shift is invalid.'],
            ]);
        }

        if (! $shift->isActive()) {
            throw ValidationException::withMessages([
                'shiftId' => ['Allocate only accepts a rider on an active Plasma shift.'],
            ]);
        }

        return $shift;
    }

    private function assertActionAllowed(DeliveryJob $job, JobAction $action): void
    {
        $allowed = JobAction::forStatus($job->status);

        if (in_array($action, $allowed, true)) {
            return;
        }

        $allowedLabels = array_map(
            static fn (JobAction $candidate): string => $candidate->value,
            $allowed,
        );

        throw ValidationException::withMessages([
            'action' => [
                sprintf(
                    'Job is %s. Allowed actions: %s.',
                    $job->status->value,
                    $allowedLabels === [] ? 'none' : implode(', ', $allowedLabels),
                ),
            ],
        ]);
    }

    private function parseTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function nextReference(): string
    {
        $sequence = DeliveryJob::query()->count() + 1;

        while (DeliveryJob::query()->where('reference', sprintf('JB-%04d', $sequence))->exists()) {
            $sequence++;
        }

        return sprintf('JB-%04d', $sequence);
    }

    private function normaliseOptionalText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }
}
