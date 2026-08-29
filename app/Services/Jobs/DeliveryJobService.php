<?php

namespace App\Services\Jobs;

use App\Enums\JobScope;
use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

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
