<?php

namespace App\Http\Resources;

use App\Models\Bike;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape matches Plasma Controller bike options
 * (`lastRecordedMileage` in `app/routes/shifts.tsx`).
 *
 * @mixin Bike
 */
class BikeResource extends JsonResource
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
        ];
    }
}
