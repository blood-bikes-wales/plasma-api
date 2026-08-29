<?php

namespace App\Http\Resources;

use App\Models\Bike;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bike log detail including append-only mileage history.
 *
 * @mixin Bike
 */
class BikeDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'registration' => $this->registration,
            'lastRecordedMileage' => $this->last_recorded_mileage,
            'mileageHistory' => MileageReadingResource::collection(
                $this->whenLoaded('mileageReadings'),
            ),
        ];
    }
}
