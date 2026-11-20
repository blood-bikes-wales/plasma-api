<?php

namespace App\Http\Resources;

use App\Models\Bike;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape matches Plasma Controller bike options
 * (`lastRecordedMileage` in `app/routes/shifts.tsx`).
 *
 * The previous shift's note (`lastShiftNote`, `lastShiftNoteAt`) is only included
 * when the caller eager loaded `latestLoggedOffShift`. Only the log on and bike
 * management endpoints do, so the directory (open to riders and drivers) never
 * exposes it.
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
        $withNote = $this->relationLoaded('latestLoggedOffShift');
        $note = $withNote ? $this->latestLoggedOffShift?->faults : null;

        return [
            'id' => $this->id,
            'registration' => $this->registration,
            'area' => $this->serviceArea?->code,
            'status' => $this->isActive() ? 'active' : 'retired',
            'lastRecordedMileage' => $this->currentMileage(),
            'purchasedAt' => $this->purchased_at?->toDateString(),
            $this->mergeWhen($withNote, fn (): array => [
                'lastShiftNote' => $note,
                'lastShiftNoteAt' => $note === null
                    ? null
                    : $this->latestLoggedOffShift?->logged_off_at?->toIso8601String(),
            ]),
        ];
    }
}
