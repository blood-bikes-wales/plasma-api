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
        ];
    }
}
