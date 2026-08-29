<?php

namespace App\Http\Resources;

use App\Models\DeliveryJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a Plasma delivery job.
 *
 * @mixin DeliveryJob
 */
class DeliveryJobResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'isRelay' => (bool) $this->is_relay,
            'parentJobId' => $this->parent_job_id,
            'legNumber' => $this->leg_number,
            'sender' => [
                'name' => $this->sender_name,
                'phone' => $this->sender_phone,
                'organisation' => $this->sender_organisation,
            ],
            'collection' => [
                'placeId' => $this->collection_place_id,
                'address' => $this->collection_address,
                'latitude' => $this->collection_latitude,
                'longitude' => $this->collection_longitude,
            ],
            'delivery' => [
                'placeId' => $this->delivery_place_id,
                'address' => $this->delivery_address,
                'latitude' => $this->delivery_latitude,
                'longitude' => $this->delivery_longitude,
            ],
            'contents' => $this->contents,
            'serviceAreas' => $this->service_areas,
            'createdAt' => $this->created_at?->toIso8601String(),
            'allocatedRider' => $this->allocated_rider_id === null ? null : [
                'id' => (string) $this->allocated_rider_id,
                'name' => $this->allocated_rider_name,
                'shiftId' => $this->operational_shift_id,
                'allocatedAt' => $this->allocated_at?->toIso8601String(),
            ],
            'collectionRecord' => $this->collected_at === null ? null : [
                'contentsConfirmed' => $this->contents_confirmed,
                'suitablySealed' => $this->suitably_sealed,
                'sealNumber' => $this->seal_number,
                'receiptNumber' => $this->receipt_number,
                'collectedAt' => $this->collected_at?->toIso8601String(),
            ],
            'deliveryRecord' => $this->delivered_at === null ? null : [
                'recipient' => $this->recipient,
                'deliveredAt' => $this->delivered_at?->toIso8601String(),
            ],
            'cancellation' => $this->cancelled_at === null ? null : [
                'reason' => $this->cancellation_reason,
                'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            ],
            'legs' => $this->when(
                $this->is_relay && $this->relationLoaded('legs'),
                fn () => DeliveryJobResource::collection($this->legs),
            ),
            'allowedActions' => $this->allowedActionNames(),
        ];
    }
}
