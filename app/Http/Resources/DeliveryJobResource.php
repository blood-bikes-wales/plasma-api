<?php

namespace App\Http\Resources;

use App\Authorization\Capability;
use App\Authorization\RequestRoles;
use App\Enums\JobType;
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
            'jobType' => $this->job_type->value,
            'milkLitres' => $this->milk_litres,
            // Deliberately not role-gated: every role that can view jobs is told so they can be sensitive. The mother's contact below is the gated part.
            'isBereavement' => $this->is_bereavement,
            'isRelay' => $isRelay,
            'parentJobId' => $this->parent_job_id,
            'legNumber' => $this->leg_number,
            'sender' => [
                'name' => $this->sender_name,
                'phone' => $this->sender_phone,
                'organisation' => $this->sender_organisation,
            ],
            // Personal data about a member of the public: only roles that can create jobs (admin, controller) get the key at all.
            'mother' => $this->when(
                RequestRoles::hasCapability($request, Capability::CreateJob),
                fn () => $this->job_type === JobType::Milk ? [
                    'name' => $this->mother_name,
                    'phone' => $this->mother_phone,
                    'secondaryPhone' => $this->mother_phone_secondary,
                ] : null,
            ),
            'collection' => [
                'placeId' => $collection?->external_id,
                'address' => $collection?->address,
                'postcode' => $collection?->postcode,
                'latitude' => $collection?->latitude,
                'longitude' => $collection?->longitude,
                'notes' => $this->collection_notes,
            ],
            'delivery' => [
                'placeId' => $delivery?->external_id,
                'address' => $delivery?->address,
                'postcode' => $delivery?->postcode,
                'latitude' => $delivery?->latitude,
                'longitude' => $delivery?->longitude,
                'notes' => $this->delivery_notes,
            ],
            'estimatedCollectionAt' => $this->estimated_collection_at?->toIso8601String(),
            'estimatedDeliveryAt' => $this->estimated_delivery_at?->toIso8601String(),
            'plannedArrivalAt' => $this->planned_arrival_at?->toIso8601String(),
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
