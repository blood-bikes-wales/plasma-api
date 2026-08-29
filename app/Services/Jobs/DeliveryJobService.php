<?php

namespace App\Services\Jobs;

use App\Enums\JobAction;
use App\Enums\JobScope;
use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\OperationalShift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class DeliveryJobService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): DeliveryJob
    {
        $sender = $payload['sender'];
        $collection = $payload['collection'];
        $delivery = $payload['delivery'];

        $job = DeliveryJob::query()->create([
            'reference' => $this->nextReference(),
            'status' => JobStatus::New,
            'sender_name' => $sender['name'],
            'sender_phone' => $sender['phone'],
            'sender_organisation' => $this->normaliseOptionalText($sender['organisation'] ?? null),
            'collection_place_id' => $collection['placeId'],
            'collection_address' => $collection['address'],
            'collection_latitude' => $collection['latitude'],
            'collection_longitude' => $collection['longitude'],
            'delivery_place_id' => $delivery['placeId'],
            'delivery_address' => $delivery['address'],
            'delivery_latitude' => $delivery['latitude'],
            'delivery_longitude' => $delivery['longitude'],
            'contents' => $payload['contents'],
            'service_areas' => array_values($payload['serviceAreas']),
            'created_by_user_id' => $actor->id,
        ]);

        Log::info('Delivery job created.', [
            'job_id' => $job->id,
            'reference' => $job->reference,
            'user_id' => $actor->id,
        ]);

        return $job;
    }

    /**
     * @return Collection<int, DeliveryJob>
     */
    public function listByScope(JobScope $scope): Collection
    {
        return DeliveryJob::query()
            ->whereIn('status', $scope->statuses())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function allocate(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertActionAllowed($locked, JobAction::Allocate);

            $shift = $this->lockActiveShift($payload['shiftId']);

            $locked->fill([
                'status' => JobStatus::Allocated,
                'operational_shift_id' => $shift->id,
                'allocated_rider_id' => $shift->rider_id,
                'allocated_rider_name' => $shift->rider_name,
                'allocated_at' => now(),
            ])->save();

            Log::info('Delivery job allocated.', [
                'job_id' => $locked->id,
                'shift_id' => $shift->id,
                'rider_id' => $shift->rider_id,
                'user_id' => $actor->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function collect(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertActionAllowed($locked, JobAction::Collect);

            $locked->fill([
                'status' => JobStatus::Collected,
                'contents_confirmed' => (bool) $payload['contentsConfirmed'],
                'suitably_sealed' => (bool) $payload['suitablySealed'],
                'seal_number' => $this->normaliseOptionalText($payload['sealNumber'] ?? null),
                'receipt_number' => $payload['receiptNumber'],
                'collected_at' => $this->parseTimestamp($payload['collectedAt'] ?? null) ?? now(),
            ])->save();

            Log::info('Delivery job collected.', [
                'job_id' => $locked->id,
                'user_id' => $actor->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliver(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertActionAllowed($locked, JobAction::Deliver);

            $locked->fill([
                'status' => JobStatus::Delivered,
                'recipient' => $payload['recipient'],
                'delivered_at' => $this->parseTimestamp($payload['deliveredAt'] ?? null) ?? now(),
            ])->save();

            Log::info('Delivery job delivered.', [
                'job_id' => $locked->id,
                'user_id' => $actor->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancel(User $actor, DeliveryJob $job, array $payload): DeliveryJob
    {
        return DB::transaction(function () use ($actor, $job, $payload): DeliveryJob {
            $locked = $this->lockJob($job->id);
            $this->assertActionAllowed($locked, JobAction::Cancel);

            $locked->fill([
                'status' => JobStatus::Cancelled,
                'cancellation_reason' => $this->normaliseOptionalText($payload['reason'] ?? null),
                'cancelled_at' => now(),
            ])->save();

            Log::info('Delivery job cancelled.', [
                'job_id' => $locked->id,
                'user_id' => $actor->id,
            ]);

            return $locked->refresh();
        });
    }

    private function lockJob(string $jobId): DeliveryJob
    {
        /** @var DeliveryJob|null $locked */
        $locked = DeliveryJob::query()->whereKey($jobId)->lockForUpdate()->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'job' => ['The selected job is invalid.'],
            ]);
        }

        return $locked;
    }

    private function lockActiveShift(string $shiftId): OperationalShift
    {
        /** @var OperationalShift|null $shift */
        $shift = OperationalShift::query()
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
