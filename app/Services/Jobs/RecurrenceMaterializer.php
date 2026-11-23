<?php

namespace App\Services\Jobs;

use App\Enums\JobType;
use App\Enums\RecurrenceFrequency;
use App\Models\DeliveryRequest;
use App\Models\JobRecurrence;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Creates New delivery requests for each occurrence date in a series' rolling horizon.
 */
final class RecurrenceMaterializer
{
    public function __construct(private readonly DeliveryJobService $jobs) {}

    /**
     * @return list<string> Newly created request/leg ids
     */
    public function materialize(JobRecurrence $recurrence, ?User $actor = null): array
    {
        if (! $recurrence->is_active) {
            return [];
        }

        $recurrence->loadMissing([
            'senderParty',
            'collectionPlace',
            'deliveryPlace',
            'milkDetail.motherParty',
            'serviceAreas',
            'createdBy',
        ]);

        $actor ??= $recurrence->createdBy;
        if ($actor === null) {
            return [];
        }

        $existingDates = DeliveryRequest::query()
            ->where('recurrence_id', $recurrence->id)
            ->whereNotNull('occurrence_date')
            ->pluck('occurrence_date')
            ->map(static fn (CarbonInterface|string $date): string => Carbon::parse($date)->toDateString())
            ->all();
        $existingLookup = array_fill_keys($existingDates, true);

        $createdIds = [];
        foreach ($this->occurrenceDates($recurrence) as $date) {
            $dateKey = $date->toDateString();
            if (isset($existingLookup[$dateKey])) {
                continue;
            }

            $scheduledAt = $this->scheduledAtFor($recurrence, $date);
            $leg = $this->jobs->createFromBlueprint(
                $actor,
                $this->blueprintPayload($recurrence, $scheduledAt),
                [
                    'recurrenceId' => $recurrence->id,
                    'occurrenceDate' => $dateKey,
                    'senderPartyId' => $recurrence->sender_party_id,
                    'motherPartyId' => $recurrence->milkDetail?->mother_party_id,
                    'collectionPlaceId' => $recurrence->collection_place_id,
                    'deliveryPlaceId' => $recurrence->delivery_place_id,
                ],
            );
            $createdIds[] = $leg->id;
            $existingLookup[$dateKey] = true;
        }

        if ($createdIds !== []) {
            Log::info('Recurrence occurrences materialized.', [
                'recurrence_id' => $recurrence->id,
                'created_count' => count($createdIds),
            ]);
        }

        return $createdIds;
    }

    /**
     * @return list<CarbonInterface>
     */
    public function occurrenceDates(JobRecurrence $recurrence, ?CarbonInterface $now = null): array
    {
        $tz = $recurrence->timezone ?: 'Europe/London';
        $nowLocal = ($now ?? Carbon::now($tz))->copy()->timezone($tz)->startOfDay();
        $startsOn = Carbon::parse($recurrence->starts_on->toDateString(), $tz)->startOfDay();
        $cursor = $nowLocal->greaterThan($startsOn) ? $nowLocal->copy() : $startsOn->copy();
        $horizonEnd = $nowLocal->copy()->addDays(max(1, (int) $recurrence->horizon_days))->startOfDay();
        $endsOn = $recurrence->ends_on === null
            ? null
            : Carbon::parse($recurrence->ends_on->toDateString(), $tz)->startOfDay();

        if ($endsOn !== null && $endsOn->lessThan($horizonEnd)) {
            $horizonEnd = $endsOn->copy();
        }

        $dates = [];
        while ($cursor->lessThanOrEqualTo($horizonEnd)) {
            if ($this->matchesFrequency($recurrence, $cursor)) {
                $dates[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    private function matchesFrequency(JobRecurrence $recurrence, CarbonInterface $date): bool
    {
        if ($recurrence->frequency === RecurrenceFrequency::Daily) {
            return true;
        }

        $weekdays = array_map('intval', $recurrence->by_weekday ?? []);
        if ($weekdays === []) {
            return false;
        }

        return in_array($date->dayOfWeekIso, $weekdays, true);
    }

    private function scheduledAtFor(JobRecurrence $recurrence, CarbonInterface $date): string
    {
        $tz = $recurrence->timezone ?: 'Europe/London';
        $time = (string) $recurrence->time_of_day;
        if (strlen($time) === 5) {
            $time .= ':00';
        }

        return Carbon::parse($date->toDateString().' '.$time, $tz)->utc()->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    private function blueprintPayload(JobRecurrence $recurrence, string $scheduledAt): array
    {
        $sender = $recurrence->senderParty;
        $collection = $recurrence->collectionPlace;
        $delivery = $recurrence->deliveryPlace;
        $payload = [
            'jobType' => $recurrence->job_type->value,
            'isBereavement' => $recurrence->is_bereavement,
            'sender' => [
                'name' => $sender->name,
                'phone' => $sender->phone,
                'organisation' => $sender->organisation,
            ],
            'collection' => [
                'placeId' => $collection->external_id,
                'address' => $collection->address,
                'postcode' => $collection->postcode,
                'latitude' => $collection->latitude,
                'longitude' => $collection->longitude,
                'notes' => $recurrence->collection_notes,
            ],
            'delivery' => [
                'placeId' => $delivery->external_id,
                'address' => $delivery->address,
                'postcode' => $delivery->postcode,
                'latitude' => $delivery->latitude,
                'longitude' => $delivery->longitude,
                'notes' => $recurrence->delivery_notes,
            ],
            'contents' => $recurrence->content_label_snapshot,
            'serviceAreas' => $recurrence->serviceAreas->pluck('code')->values()->all(),
            'scheduledCollectionAt' => $scheduledAt,
        ];

        if ($recurrence->job_type !== JobType::Milk) {
            return $payload;
        }

        $mother = $recurrence->milkDetail?->motherParty;
        $payload['milkLitres'] = $recurrence->milkDetail?->milk_litres;
        $payload['mother'] = [
            'name' => $mother?->name,
            'phone' => $mother?->phone,
            'secondaryPhone' => $mother?->phone_secondary,
        ];

        return $payload;
    }
}
