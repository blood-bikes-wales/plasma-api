<?php

namespace App\Services\Jobs;

use App\Enums\JobAction;
use App\Enums\JobScope;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\DeliveryLeg;
use App\Models\DeliveryRequest;
use App\Models\JobContentType;
use App\Models\MilkJobDetail;
use App\Models\OperationalShift;
use App\Models\Party;
use App\Models\Place;
use App\Models\ServiceArea;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DeliveryJobService
{
    public function __construct(private readonly RelayJobStatusAggregator $relayStatus) {}

    /**
     * Relations for a list/detail top-level request (relay or soon-to-be flattened single leg).
     *
     * @var list<string>
     */
    private const array REQUEST_RELATIONS = [
        'senderParty',
        'milkDetail.motherParty',
        'contentType',
        'serviceAreas',
        'legs.collectionPlace',
        'legs.deliveryPlace',
        'legs.operationalShift.rider',
        'legs.request.senderParty',
        'legs.request.milkDetail.motherParty',
        'legs.request.serviceAreas',
    ];

    /**
     * @var list<string>
     */
    private const array LEG_RELATIONS = [
        'collectionPlace',
        'deliveryPlace',
        'operationalShift.rider',
        'request.senderParty',
        'request.milkDetail.motherParty',
        'request.contentType',
        'request.serviceAreas',
        'request.legs',
    ];

    public const int RECENT_COMPLETED_HOURS = 24;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): DeliveryLeg
    {
        return DB::transaction(function () use ($actor, $payload): DeliveryLeg {
            $sender = $payload['sender'];
            $collection = $payload['collection'];
            $delivery = $payload['delivery'];

            $collectionPlace = $this->upsertPlace($collection);
            $deliveryPlace = $this->upsertPlace($delivery);

            $jobType = JobType::tryFrom((string) ($payload['jobType'] ?? '')) ?? JobType::Standard;
            $mother = $jobType === JobType::Milk ? ($payload['mother'] ?? null) : null;

            $senderParty = Party::query()->create([
                'name' => $sender['name'],
                'phone' => $sender['phone'],
                'organisation' => $this->normaliseOptionalText($sender['organisation'] ?? null),
            ]);

            $contents = (string) $payload['contents'];
            $contentTypeId = JobContentType::query()
                ->where('name', $contents)
                ->value('id');

            $sharedId = (string) Str::uuid();
            $reference = $this->nextReference();

            $request = DeliveryRequest::query()->create([
                'id' => $sharedId,
                'reference' => $reference,
                'status' => JobStatus::New,
                'job_type' => $jobType,
                'is_bereavement' => $jobType === JobType::Milk && filter_var($payload['isBereavement'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sender_party_id' => $senderParty->id,
                'content_type_id' => $contentTypeId,
                'content_label_snapshot' => $contents,
                'scheduled_collection_at' => $this->parseTimestamp($payload['scheduledCollectionAt'] ?? null),
                'created_by_user_id' => $actor->id,
            ]);

            if ($jobType === JobType::Milk) {
                $motherParty = Party::query()->create([
                    'name' => $this->normaliseOptionalText($mother['name'] ?? null) ?? 'Unknown',
                    'phone' => $this->normaliseOptionalText($mother['phone'] ?? null) ?? '',
                    'phone_secondary' => $this->normaliseOptionalText($mother['secondaryPhone'] ?? null),
                ]);

                MilkJobDetail::query()->create([
                    'delivery_request_id' => $request->id,
                    'milk_litres' => $payload['milkLitres'],
                    'mother_party_id' => $motherParty->id,
                ]);
            }

            $request->serviceAreas()->sync($this->serviceAreaIds(array_values($payload['serviceAreas'])));

            $leg = DeliveryLeg::query()->create([
                'id' => $sharedId,
                'request_id' => $request->id,
                'leg_number' => 1,
                'reference' => $reference,
                'status' => JobStatus::New,
                'collection_place_id' => $collectionPlace->id,
                'delivery_place_id' => $deliveryPlace->id,
                'collection_notes' => $this->normaliseOptionalText($collection['notes'] ?? null),
                'delivery_notes' => $this->normaliseOptionalText($delivery['notes'] ?? null),
                'estimated_collection_at' => $this->parseTimestamp($payload['estimatedCollectionAt'] ?? null),
                'estimated_delivery_at' => $this->parseTimestamp($payload['estimatedDeliveryAt'] ?? null),
            ]);

            Log::info('Delivery job created.', [
                'job_id' => $leg->id,
                'reference' => $reference,
                'user_id' => $actor->id,
            ]);

            return $this->loadLeg($leg);
        });
    }

    /**
     * Top-level list items: multi-leg requests as aggregates; single-leg requests as the leg.
     *
     * @return Collection<int, DeliveryRequest|DeliveryLeg>
     */
    public function listByScope(JobScope $scope, bool $includeOlderCompleted = true): Collection
    {
        $query = DeliveryRequest::query()
            ->whereIn('status', $scope->statuses())
            ->with(self::REQUEST_RELATIONS);

        if ($scope === JobScope::Completed && ! $includeOlderCompleted) {
            $this->wherePastDay($query);
        }

        $requests = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->each(static fn (DeliveryRequest $request): DeliveryRequest => $request->linkLegsToParent());

        /** @var Collection<int, DeliveryRequest|DeliveryLeg> $items */
        $items = $requests->map(function (DeliveryRequest $request): DeliveryRequest|DeliveryLeg {
            if ($request->isRelay()) {
                return $request;
            }

            /** @var DeliveryLeg $leg */
            $leg = $request->legs->first();

            return $leg;
        });

        return $items->values();
    }

    /**
     * Resolve a path id to a request (relay parent) or leg.
     */
    public function resolve(string $id): DeliveryRequest|DeliveryLeg
    {
        $leg = DeliveryLeg::query()->whereKey($id)->first();
        if ($leg !== null) {
            return $this->findLeg($leg);
        }

        $request = DeliveryRequest::query()->whereKey($id)->first();
        if ($request !== null) {
            return $this->findRequest($request);
        }

        abort(404);
    }

    public function find(DeliveryRequest|DeliveryLeg $job): DeliveryRequest|DeliveryLeg
    {
        if ($job instanceof DeliveryRequest) {
            return $this->findRequest($job);
        }

        return $this->findLeg($job);
    }

    private function findRequest(DeliveryRequest $request): DeliveryRequest
    {
        return $request->load(self::REQUEST_RELATIONS)->linkLegsToParent();
    }

    private function findLeg(DeliveryLeg $leg): DeliveryLeg
    {
        return $this->loadLeg($leg);
    }

    private function loadLeg(DeliveryLeg $leg): DeliveryLeg
    {
        $leg->load(self::LEG_RELATIONS);
        $request = $leg->request;
        if ($request !== null) {
            $request->linkLegsToParent();
        }

        return $leg;
    }

    /**
     * @param  Builder<DeliveryRequest>  $query
     */
    private function wherePastDay(Builder $query): void
    {
        $since = now()->subHours(self::RECENT_COMPLETED_HOURS);

        // Same three predicates as the old single-row filter, applied to hops; plus request.updated_at
        // for multi-leg parents whose status is rolled up without a delivered_at/cancelled_at on the request.
        $query->where(function (Builder $query) use ($since): void {
            $query->whereHas('legs', function (Builder $legs) use ($since): void {
                $legs->where(function (Builder $legs) use ($since): void {
                    $legs->where('updated_at', '>=', $since)
                        ->orWhere(fn (Builder $legs) => $legs
                            ->where('status', JobStatus::Delivered)
                            ->where('delivered_at', '>=', $since))
                        ->orWhere(fn (Builder $legs) => $legs
                            ->where('status', JobStatus::Cancelled)
                            ->where('cancelled_at', '>=', $since));
                });
            })->orWhere(function (Builder $query) use ($since): void {
                $query->where('updated_at', '>=', $since)
                    ->has('legs', '>', 1);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function schedule(User $actor, DeliveryRequest|DeliveryLeg $job, array $payload): DeliveryRequest|DeliveryLeg
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryRequest|DeliveryLeg {
            $request = $this->lockRequestFor($job);

            if ($job instanceof DeliveryLeg && $job->requestIsRelay()) {
                throw ValidationException::withMessages([
                    'job' => ['Schedule a relay through the whole job, not one of its legs.'],
                ]);
            }

            if (! in_array($request->status, [JobStatus::New, JobStatus::Allocated], true)) {
                throw ValidationException::withMessages([
                    'job' => [sprintf('Job is %s. Only a New or Allocated job can be scheduled.', $request->status->value)],
                ]);
            }

            $scheduledAt = $this->parseTimestamp($payload['scheduledCollectionAt']);
            $request->fill(['scheduled_collection_at' => $scheduledAt])->save();

            Log::info('Delivery job scheduled.', [
                'job_id' => $request->id,
                'user_id' => $actor->id,
            ]);

            return $this->find($job instanceof DeliveryLeg ? $job->refresh() : $request->refresh());
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function relay(User $actor, DeliveryRequest|DeliveryLeg $job, array $payload): DeliveryRequest
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryRequest {
            $request = $this->lockRequestFor($job);
            $this->assertRelayConversionAllowed($request);

            /** @var DeliveryLeg $soleLeg */
            $soleLeg = DeliveryLeg::query()
                ->where('request_id', $request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $routePoints = $this->routePoints($soleLeg, $payload['rendezvousPoints']);
            $soleLeg->delete();
            $this->createRelayLegs($actor, $request, $routePoints);

            Log::info('Delivery job converted to a relay.', [
                'job_id' => $request->id,
                'leg_count' => count($routePoints) - 1,
                'user_id' => $actor->id,
            ]);

            return $this->findRequest($request->refresh());
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function allocate(User $actor, DeliveryRequest|DeliveryLeg $job, array $payload): DeliveryLeg
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryLeg {
            $locked = $this->lockLeg($job);
            $this->assertDirectLifecycleAllowed($locked);
            $this->assertActionAllowed($locked, JobAction::Allocate);

            if ($locked->status === JobStatus::Allocated
                && $locked->operational_shift_id !== null
                && strtolower($locked->operational_shift_id) === strtolower((string) $payload['shiftId'])) {
                return $this->reloadAfterAllocate($locked);
            }

            $previousShiftId = $locked->operational_shift_id;
            $shift = $this->lockActiveShift($payload['shiftId']);

            $locked->fill([
                'status' => JobStatus::Allocated,
                'operational_shift_id' => $shift->id,
                'allocated_at' => now(),
            ])->save();

            $updated = $this->reloadAfterAllocate($locked);
            $this->syncRelayParentFromLeg($updated);

            Log::info($previousShiftId === null ? 'Delivery job allocated.' : 'Delivery job re-allocated.', [
                'job_id' => $updated->id,
                'shift_id' => $shift->id,
                'previous_shift_id' => $previousShiftId,
                'rider_id' => $shift->rider_id,
                'user_id' => $actor->id,
            ]);

            return $updated;
        });
    }

    private function reloadAfterAllocate(DeliveryLeg $locked): DeliveryLeg
    {
        return $this->loadLeg($locked->refresh());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function collect(User $actor, DeliveryRequest|DeliveryLeg $job, array $payload): DeliveryLeg
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryLeg {
            $locked = $this->lockLeg($job);
            $this->assertDirectLifecycleAllowed($locked);
            $this->assertActionAllowed($locked, JobAction::Collect);
            $this->assertPreviousLegDelivered($locked, JobAction::Collect);

            $locked->fill([
                'status' => JobStatus::Collected,
                'contents_confirmed' => (bool) $payload['contentsConfirmed'],
                'receipt_number' => $payload['receiptNumber'],
                'collected_at' => $this->parseTimestamp($payload['collectedAt'] ?? null) ?? now(),
            ])->save();

            $updated = $this->loadLeg($locked->refresh());
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
    public function deliver(User $actor, DeliveryRequest|DeliveryLeg $job, array $payload): DeliveryLeg
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryLeg {
            $locked = $this->lockLeg($job);
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

            $updated = $this->loadLeg($locked->refresh());
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
    public function cancel(User $actor, DeliveryRequest|DeliveryLeg $job, array $payload): DeliveryRequest|DeliveryLeg
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryRequest|DeliveryLeg {
            if ($job instanceof DeliveryRequest) {
                $request = $this->lockRequestFor($job);
                $this->assertActionAllowedOnStatus($request->status, JobAction::Cancel);

                return $this->cancelRelayParent($actor, $request, $payload);
            }

            $locked = $this->lockLeg($job);
            $this->assertActionAllowed($locked, JobAction::Cancel);

            $locked->fill([
                'status' => JobStatus::Cancelled,
                'cancellation_reason' => $this->normaliseOptionalText($payload['reason'] ?? null),
                'cancelled_at' => now(),
            ])->save();

            $updated = $this->loadLeg($locked->refresh());
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
    private function routePoints(DeliveryLeg $leg, array $rendezvousPoints): array
    {
        $leg->loadMissing(['collectionPlace', 'deliveryPlace']);

        return [
            [
                'placeId' => $leg->collectionPlace->external_id,
                'address' => $leg->collectionPlace->address,
                'postcode' => $leg->collectionPlace->postcode,
                'latitude' => $leg->collectionPlace->latitude,
                'longitude' => $leg->collectionPlace->longitude,
            ],
            ...$rendezvousPoints,
            [
                'placeId' => $leg->deliveryPlace->external_id,
                'address' => $leg->deliveryPlace->address,
                'postcode' => $leg->deliveryPlace->postcode,
                'latitude' => $leg->deliveryPlace->latitude,
                'longitude' => $leg->deliveryPlace->longitude,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $routePoints
     */
    private function createRelayLegs(User $actor, DeliveryRequest $request, array $routePoints): void
    {
        $legCount = count($routePoints) - 1;

        array_map(
            function (int $index) use ($request, $routePoints): DeliveryLeg {
                $collection = $routePoints[$index];
                $delivery = $routePoints[$index + 1];

                $collectionPlace = $this->upsertPlace($collection);
                $deliveryPlace = $this->upsertPlace($delivery);

                return DeliveryLeg::query()->create([
                    'request_id' => $request->id,
                    'leg_number' => $index + 1,
                    'reference' => sprintf('%s-L%d', $request->reference, $index + 1),
                    'status' => JobStatus::New,
                    'collection_place_id' => $collectionPlace->id,
                    'delivery_place_id' => $deliveryPlace->id,
                    'planned_arrival_at' => $this->parseTimestamp($delivery['arrivesAt'] ?? null),
                ]);
            },
            range(0, $legCount - 1),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancelRelayParent(User $actor, DeliveryRequest $request, array $payload): DeliveryRequest
    {
        $reason = $this->normaliseOptionalText($payload['reason'] ?? null);
        $cancelledAt = now();

        $legs = DeliveryLeg::query()
            ->where('request_id', $request->id)
            ->lockForUpdate()
            ->get();

        $legs->each(function (DeliveryLeg $leg) use ($reason, $cancelledAt): void {
            if ($leg->status === JobStatus::Delivered || $leg->status === JobStatus::Cancelled) {
                return;
            }

            $leg->fill([
                'status' => JobStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_at' => $cancelledAt,
            ])->save();
        });

        $request->fill([
            'status' => JobStatus::Cancelled,
        ])->save();

        Log::info('Relay delivery job cancelled.', [
            'job_id' => $request->id,
            'user_id' => $actor->id,
        ]);

        return $this->findRequest($request->refresh());
    }

    private function syncRelayParentFromLeg(DeliveryLeg $leg): void
    {
        if (! $leg->requestIsRelay()) {
            // Keep single-leg request status in sync with its only hop.
            $request = DeliveryRequest::query()
                ->whereKey($leg->request_id)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                return;
            }

            $request->status = $leg->status;
            $request->save();

            return;
        }

        $request = DeliveryRequest::query()
            ->whereKey($leg->request_id)
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            return;
        }

        $legs = DeliveryLeg::query()
            ->where('request_id', $request->id)
            ->orderBy('leg_number')
            ->get();

        $request->status = $this->relayStatus->aggregate($legs);
        $request->save();
    }

    private function assertRelayConversionAllowed(DeliveryRequest $request): void
    {
        if ($request->isRelay()) {
            throw ValidationException::withMessages([
                'job' => ['This job is already a relay.'],
            ]);
        }

        if ($request->status !== JobStatus::New) {
            throw ValidationException::withMessages([
                'job' => ['Only a new job can be converted to a relay.'],
            ]);
        }
    }

    private function assertDirectLifecycleAllowed(DeliveryLeg $leg): void
    {
        // Lifecycle always targets a leg; requests are rejected by lockLeg.
    }

    private function lockRequestFor(DeliveryRequest|DeliveryLeg $job): DeliveryRequest
    {
        $requestId = $job instanceof DeliveryRequest ? $job->id : $job->request_id;

        /** @var DeliveryRequest|null $locked */
        $locked = DeliveryRequest::query()
            ->with(self::REQUEST_RELATIONS)
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'job' => ['The selected job is invalid.'],
            ]);
        }

        return $locked->linkLegsToParent();
    }

    private function lockLeg(DeliveryRequest|DeliveryLeg $job): DeliveryLeg
    {
        if ($job instanceof DeliveryRequest) {
            throw ValidationException::withMessages([
                'job' => ['Manage relay jobs through their individual legs.'],
            ]);
        }

        /** @var DeliveryLeg|null $locked */
        $locked = DeliveryLeg::query()
            ->with(self::LEG_RELATIONS)
            ->whereKey($job->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'job' => ['The selected job is invalid.'],
            ]);
        }

        $locked->request?->linkLegsToParent();

        return $locked;
    }

    private function assertPreviousLegDelivered(DeliveryLeg $leg, JobAction $action): void
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

    private function assertActionAllowed(DeliveryLeg $job, JobAction $action): void
    {
        $this->assertActionAllowedOnStatus($job->status, $action);
    }

    private function assertActionAllowedOnStatus(JobStatus $status, JobAction $action): void
    {
        $allowed = JobAction::forStatus($status);

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
                    $status->value,
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

        return Carbon::parse($value)->utc();
    }

    private function nextReference(): string
    {
        $sequence = DeliveryRequest::query()->count() + 1;

        while (DeliveryRequest::query()->where('reference', sprintf('JB-%04d', $sequence))->exists()) {
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
