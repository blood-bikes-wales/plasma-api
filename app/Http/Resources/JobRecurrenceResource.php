<?php

namespace App\Http\Resources;

use App\Enums\JobType;
use App\Models\JobRecurrence;
use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobRecurrence
 */
class JobRecurrenceResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JobRecurrence $series */
        $series = $this->resource;
        $sender = $series->senderParty;
        $mother = $series->milkDetail?->motherParty;
        $time = (string) $series->time_of_day;
        if (strlen($time) >= 8) {
            $time = substr($time, 0, 5);
        }

        return [
            'id' => $series->id,
            'jobType' => $series->job_type->value,
            'milkLitres' => $series->milkDetail?->milk_litres,
            'isBereavement' => $series->is_bereavement,
            'sender' => [
                'name' => $sender?->name,
                'phone' => $sender?->phone,
                'organisation' => $sender?->organisation,
            ],
            'mother' => $series->job_type === JobType::Milk ? [
                'name' => $mother?->name,
                'phone' => $mother?->phone,
                'secondaryPhone' => $mother?->phone_secondary,
            ] : null,
            'collection' => $this->placePayload($series->collectionPlace, $series->collection_notes),
            'delivery' => $this->placePayload($series->deliveryPlace, $series->delivery_notes),
            'contents' => $series->content_label_snapshot,
            'serviceAreas' => $series->relationLoaded('serviceAreas')
                ? $series->serviceAreas->pluck('code')->values()->all()
                : [],
            'timezone' => $series->timezone,
            'timeOfDay' => $time,
            'frequency' => $series->frequency->value,
            'byWeekday' => $series->by_weekday,
            'startsOn' => $series->starts_on?->toDateString(),
            'endsOn' => $series->ends_on?->toDateString(),
            'isActive' => $series->is_active,
            'horizonDays' => $series->horizon_days,
            'createdAt' => $series->created_at?->toIso8601String(),
            'updatedAt' => $series->updated_at?->toIso8601String(),
            'occurrences' => $this->when(
                $series->relationLoaded('occurrences'),
                fn () => $series->occurrences->map(static fn ($request) => [
                    'id' => $request->id,
                    'reference' => $request->reference,
                    'status' => $request->status->value,
                    'occurrenceDate' => $request->occurrence_date?->toDateString(),
                    'scheduledCollectionAt' => $request->scheduled_collection_at?->toIso8601String(),
                ])->values()->all(),
            ),
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
