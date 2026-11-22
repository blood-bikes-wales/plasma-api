<?php

namespace App\Http\Resources;

use App\Authorization\Capability;
use App\Authorization\RequestRoles;
use App\Enums\JobType;
use App\Models\DeliveryLeg;
use App\Models\DeliveryRequest;
use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a Plasma delivery job (request aggregate or leg).
 *
 * @mixin DeliveryRequest|DeliveryLeg
 */
class DeliveryJobResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof DeliveryRequest) {
            return $this->requestToArray($request);
        }

        /** @var DeliveryLeg $leg */
        $leg = $this->resource;

        return $this->legToArray($request, $leg);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestToArray(Request $httpRequest): array
    {
        /** @var DeliveryRequest $job */
        $job = $this->resource;
        [$firstLeg, $lastLeg] = $job->endpointLegs();
        $collection = $firstLeg?->collectionPlace;
        $delivery = $lastLeg?->deliveryPlace;
        $sender = $job->senderParty;
        $motherParty = $job->milkDetail?->motherParty;
        $isRelay = $job->isRelay();

        return [
            'id' => $job->id,
            'reference' => $job->reference,
            'status' => $job->status->value,
            'jobType' => $job->job_type->value,
            'milkLitres' => $job->milkDetail?->milk_litres,
            'isBereavement' => $job->is_bereavement,
            'isRelay' => $isRelay,
            'parentJobId' => null,
            'legNumber' => null,
            'sender' => [
                'name' => $sender?->name,
                'phone' => $sender?->phone,
                'organisation' => $sender?->organisation,
            ],
            'mother' => $this->when(
                RequestRoles::hasCapability($httpRequest, Capability::CreateJob),
                fn () => $job->job_type === JobType::Milk ? [
                    'name' => $motherParty?->name,
                    'phone' => $motherParty?->phone,
                    'secondaryPhone' => $motherParty?->phone_secondary,
                ] : null,
            ),
            'collection' => $this->placePayload($collection, null),
            'delivery' => $this->placePayload($delivery, null),
            'estimatedCollectionAt' => null,
            'estimatedDeliveryAt' => null,
            'plannedArrivalAt' => null,
            'scheduledCollectionAt' => $job->scheduled_collection_at?->toIso8601String(),
            'needsMotherContact' => $this->when(
                RequestRoles::hasCapability($httpRequest, Capability::CreateJob),
                fn () => $job->needsMotherContact(),
            ),
            'contents' => $job->content_label_snapshot,
            'serviceAreas' => $this->whenLoaded(
                'serviceAreas',
                fn () => $job->serviceAreas->pluck('code')->values()->all(),
                [],
            ),
            'createdAt' => $job->created_at?->toIso8601String(),
            'allocatedRider' => null,
            'collectionRecord' => null,
            'deliveryRecord' => null,
            'cancellation' => null,
            'legs' => $this->when(
                $isRelay && $job->relationLoaded('legs'),
                fn () => DeliveryJobResource::collection($job->legs),
            ),
            'waitingForLeg' => null,
            'allowedActions' => $job->allowedActionNames(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legToArray(Request $httpRequest, DeliveryLeg $leg): array
    {
        $deliveryRequest = $leg->request;
        $collection = $leg->collectionPlace;
        $delivery = $leg->deliveryPlace;
        $shift = $leg->operationalShift;
        $rider = $shift?->rider;
        $sender = $deliveryRequest?->senderParty;
        $motherParty = $deliveryRequest?->milkDetail?->motherParty;
        $isRelayLeg = $leg->requestIsRelay();

        return [
            'id' => $leg->id,
            'reference' => $leg->reference,
            'status' => $leg->status->value,
            'jobType' => $deliveryRequest?->job_type->value,
            'milkLitres' => $deliveryRequest?->milkDetail?->milk_litres,
            'isBereavement' => (bool) ($deliveryRequest?->is_bereavement ?? false),
            'isRelay' => false,
            'parentJobId' => $leg->apiParentJobId(),
            'legNumber' => $leg->apiLegNumber(),
            'sender' => [
                'name' => $sender?->name,
                'phone' => $sender?->phone,
                'organisation' => $sender?->organisation,
            ],
            'mother' => $this->when(
                RequestRoles::hasCapability($httpRequest, Capability::CreateJob),
                fn () => $deliveryRequest?->job_type === JobType::Milk ? [
                    'name' => $motherParty?->name,
                    'phone' => $motherParty?->phone,
                    'secondaryPhone' => $motherParty?->phone_secondary,
                ] : null,
            ),
            'collection' => $this->placePayload($collection, $leg->collection_notes),
            'delivery' => $this->placePayload($delivery, $leg->delivery_notes),
            'estimatedCollectionAt' => $leg->estimated_collection_at?->toIso8601String(),
            'estimatedDeliveryAt' => $leg->estimated_delivery_at?->toIso8601String(),
            'plannedArrivalAt' => $leg->planned_arrival_at?->toIso8601String(),
            'scheduledCollectionAt' => $deliveryRequest?->scheduled_collection_at?->toIso8601String(),
            'needsMotherContact' => $this->when(
                RequestRoles::hasCapability($httpRequest, Capability::CreateJob),
                fn () => $leg->needsMotherContact(),
            ),
            'contents' => $deliveryRequest?->content_label_snapshot,
            'serviceAreas' => $deliveryRequest?->relationLoaded('serviceAreas')
                ? $deliveryRequest->serviceAreas->pluck('code')->values()->all()
                : [],
            'createdAt' => $leg->created_at?->toIso8601String(),
            'allocatedRider' => $leg->operational_shift_id === null ? null : [
                'id' => (string) ($rider?->external_id),
                'name' => $rider?->name,
                'shiftId' => $leg->operational_shift_id,
                'allocatedAt' => $leg->allocated_at?->toIso8601String(),
            ],
            'collectionRecord' => $leg->collected_at === null ? null : [
                'contentsConfirmed' => $leg->contents_confirmed,
                'receiptNumber' => $leg->receipt_number,
                'collectedAt' => $leg->collected_at?->toIso8601String(),
            ],
            'deliveryRecord' => $leg->delivered_at === null ? null : [
                'recipient' => $leg->no_recipient ? null : $leg->recipient,
                'noRecipient' => $leg->no_recipient,
                'deliveredAt' => $leg->delivered_at?->toIso8601String(),
            ],
            'cancellation' => $leg->cancelled_at === null ? null : [
                'reason' => $leg->cancellation_reason,
                'cancelledAt' => $leg->cancelled_at?->toIso8601String(),
            ],
            'waitingForLeg' => $leg->waitingForLeg(),
            'allowedActions' => $leg->allowedActionNames(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placePayload(?Place $place, ?string $notes): array
    {
        return [
            'placeId' => $place?->external_id,
            'address' => $place?->address,
            'postcode' => $place?->postcode,
            'latitude' => $place?->latitude,
            'longitude' => $place?->longitude,
            'notes' => $notes,
        ];
    }
}
