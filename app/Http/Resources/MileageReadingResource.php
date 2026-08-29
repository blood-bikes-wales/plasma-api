<?php

namespace App\Http\Resources;

use App\Models\MileageReading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MileageReading
 */
class MileageReadingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mileage' => $this->mileage,
            'reason' => $this->reason,
            'recordedAt' => $this->recorded_at?->toIso8601String(),
            'shiftId' => $this->operational_shift_id,
        ];
    }
}
