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
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'registration' => $this->registration,
            'area' => $this->area,
            'status' => $this->status,
            'lastRecordedMileage' => $this->last_recorded_mileage,
            'purchasedAt' => $this->purchased_at?->toDateString(),
        ];
    }
}
