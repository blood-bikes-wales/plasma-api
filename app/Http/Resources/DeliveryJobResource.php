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
        $collection = $this->collectionPlace;
        $delivery = $this->deliveryPlace;
        $shift = $this->operationalShift;
        $rider = $shift?->rider;
        $isRelay = $this->isRelayParent();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'isRelay' => $isRelay,
            'parentJobId' => $this->parent_job_id,
            'legNumber' => $this->leg_number,
            'sender' => [
                'name' => $this->sender_name,
                'phone' => $this->sender_phone,
                'organisation' => $this->sender_organisation,
            ],
            'collection' => [
                'placeId' => $collection?->external_id,
                'address' => $collection?->address,
                'latitude' => $collection?->latitude,
                'longitude' => $collection?->longitude,
            ],
            'delivery' => [
                'placeId' => $delivery?->external_id,
                'address' => $delivery?->address,
                'latitude' => $delivery?->latitude,
                'longitude' => $delivery?->longitude,
            ],
            'contents' => $this->contents,
            'serviceAreas' => $this->whenLoaded(
                'serviceAreas',
                fn () => $this->serviceAreas->pluck('code')->values()->all(),
                [],
            ),
            'createdAt' => $this->created_at?->toIso8601String(),
            'allocatedRider' => $this->operational_shift_id === null ? null : [
                'id' => (string) ($rider?->external_id),
                'name' => $rider?->name,
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
                $isRelay && $this->relationLoaded('legs'),
                fn () => DeliveryJobResource::collection($this->legs),
            ),
            'allowedActions' => $this->allowedActionNames(),
        ];
    }
}
