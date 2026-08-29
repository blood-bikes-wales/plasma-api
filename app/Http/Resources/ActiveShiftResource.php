<?php

namespace App\Http\Resources;

use App\Models\OperationalShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape matches Plasma Controller `ActiveShift`
 * (`app/routes/shifts.tsx`).
 *
 * @mixin OperationalShift
 */
class ActiveShiftResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'riderId' => (string) $this->rider_id,
            'riderName' => $this->rider_name,
            'bikeId' => $this->bike_id,
            'bikeRegistration' => $this->bike->registration,
            'startMileage' => $this->start_mileage,
            'startedAt' => $this->logged_on_at?->toIso8601String(),
            'mileageVarianceReason' => $this->mileage_variance_reason,
        ];
    }
}
