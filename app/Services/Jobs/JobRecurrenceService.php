<?php

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\RecurrenceFrequency;
use App\Models\DeliveryLeg;
use App\Models\DeliveryRequest;
use App\Models\JobContentType;
use App\Models\JobRecurrence;
use App\Models\MilkRecurrenceDetail;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class JobRecurrenceService
{
    public function __construct(
        private readonly DeliveryJobService $jobs,
        private readonly RecurrenceMaterializer $materializer,
    ) {}

    /**
     * @return Collection<int, JobRecurrence>
     */
    public function list(?bool $activeOnly = null): Collection
    {
        $query = JobRecurrence::query()
            ->with($this->listRelations())
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($activeOnly === true) {
            $query->where('is_active', true);
        }

        if ($activeOnly === false) {
            $query->where('is_active', false);
        }

        return $query->get();
    }

    public function find(JobRecurrence $recurrence): JobRecurrence
    {
        return $recurrence->load($this->listRelations());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): JobRecurrence
    {
        $recurrence = DB::transaction(function () use ($actor, $payload): JobRecurrence {
            $this->assertSchedule($payload);

            $jobType = JobType::tryFrom((string) ($payload['jobType'] ?? '')) ?? JobType::Standard;
            $collectionPlace = $this->jobs->upsertPlace($payload['collection']);
            $deliveryPlace = $this->jobs->upsertPlace($payload['delivery']);
            $sender = $payload['sender'];

            $senderParty = Party::query()->create([
                'name' => $sender['name'],
                'phone' => $sender['phone'],
                'organisation' => $this->normaliseOptionalText($sender['organisation'] ?? null),
            ]);

            $contents = (string) $payload['contents'];
            $contentTypeId = JobContentType::query()
                ->where('name', $contents)
                ->value('id');

            $recurrence = JobRecurrence::query()->create([
                'job_type' => $jobType,
                'is_bereavement' => $jobType === JobType::Milk
                    && filter_var($payload['isBereavement'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sender_party_id' => $senderParty->id,
                'content_type_id' => $contentTypeId,
                'content_label_snapshot' => $contents,
                'collection_place_id' => $collectionPlace->id,
                'delivery_place_id' => $deliveryPlace->id,
                'collection_notes' => $this->normaliseOptionalText($payload['collection']['notes'] ?? null),
                'delivery_notes' => $this->normaliseOptionalText($payload['delivery']['notes'] ?? null),
                'timezone' => (string) ($payload['timezone'] ?? 'Europe/London'),
                'time_of_day' => $this->normaliseTimeOfDay($payload['timeOfDay']),
                'frequency' => RecurrenceFrequency::from((string) $payload['frequency']),
                'by_weekday' => $this->normaliseWeekdays($payload),
                'starts_on' => $payload['startsOn'],
                'ends_on' => $payload['endsOn'] ?? null,
                'is_active' => true,
                'horizon_days' => (int) ($payload['horizonDays'] ?? 14),
                'created_by_user_id' => $actor->id,
            ]);

            if ($jobType === JobType::Milk) {
                $mother = $payload['mother'];
                $motherParty = Party::query()->create([
                    'name' => $this->normaliseOptionalText($mother['name'] ?? null) ?? 'Unknown',
                    'phone' => $this->normaliseOptionalText($mother['phone'] ?? null) ?? '',
                    'phone_secondary' => $this->normaliseOptionalText($mother['secondaryPhone'] ?? null),
                ]);
                MilkRecurrenceDetail::query()->create([
                    'job_recurrence_id' => $recurrence->id,
                    'milk_litres' => $payload['milkLitres'],
                    'mother_party_id' => $motherParty->id,
                ]);
            }

            $recurrence->serviceAreas()->sync(
                $this->jobs->serviceAreaIds(array_values($payload['serviceAreas'])),
            );

            Log::info('Job recurrence created.', [
                'recurrence_id' => $recurrence->id,
                'user_id' => $actor->id,
            ]);

            return $recurrence;
        });

        $this->materializer->materialize($recurrence, $actor);

        return $this->find($recurrence);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $actor, JobRecurrence $recurrence, array $payload): JobRecurrence
    {
        DB::transaction(function () use ($actor, $recurrence, $payload): void {
            $locked = JobRecurrence::query()->whereKey($recurrence->id)->lockForUpdate()->firstOrFail();
            $this->assertSchedule(array_merge([
                'frequency' => $locked->frequency->value,
                'byWeekday' => $locked->by_weekday,
                'timeOfDay' => $locked->time_of_day,
                'startsOn' => $locked->starts_on?->toDateString(),
                'endsOn' => $locked->ends_on?->toDateString(),
                'timezone' => $locked->timezone,
                'horizonDays' => $locked->horizon_days,
            ], $payload));

            if (isset($payload['collection'])) {
                $place = $this->jobs->upsertPlace($payload['collection']);
                $locked->collection_place_id = $place->id;
                $locked->collection_notes = $this->normaliseOptionalText($payload['collection']['notes'] ?? null);
            }

            if (isset($payload['delivery'])) {
                $place = $this->jobs->upsertPlace($payload['delivery']);
                $locked->delivery_place_id = $place->id;
                $locked->delivery_notes = $this->normaliseOptionalText($payload['delivery']['notes'] ?? null);
            }

            if (isset($payload['sender'])) {
                $locked->senderParty->fill([
                    'name' => $payload['sender']['name'],
                    'phone' => $payload['sender']['phone'],
                    'organisation' => $this->normaliseOptionalText($payload['sender']['organisation'] ?? null),
                ])->save();
            }

            if (isset($payload['contents'])) {
                $contents = (string) $payload['contents'];
                $locked->content_label_snapshot = $contents;
                $locked->content_type_id = JobContentType::query()
                    ->where('name', $contents)
                    ->value('id');
            }

            if (array_key_exists('isBereavement', $payload) && $locked->job_type === JobType::Milk) {
                $locked->is_bereavement = filter_var($payload['isBereavement'], FILTER_VALIDATE_BOOLEAN);
            }

            if (isset($payload['timezone'])) {
                $locked->timezone = (string) $payload['timezone'];
            }
            if (isset($payload['timeOfDay'])) {
                $locked->time_of_day = $this->normaliseTimeOfDay($payload['timeOfDay']);
            }
            if (isset($payload['frequency'])) {
                $locked->frequency = RecurrenceFrequency::from((string) $payload['frequency']);
            }
            if (array_key_exists('byWeekday', $payload) || isset($payload['frequency'])) {
                $locked->by_weekday = $this->normaliseWeekdays(array_merge([
                    'frequency' => $locked->frequency->value,
                    'byWeekday' => $payload['byWeekday'] ?? $locked->by_weekday,
                ], $payload));
            }
            if (isset($payload['startsOn'])) {
                $locked->starts_on = $payload['startsOn'];
            }
            if (array_key_exists('endsOn', $payload)) {
                $locked->ends_on = $payload['endsOn'];
            }
            if (isset($payload['horizonDays'])) {
                $locked->horizon_days = (int) $payload['horizonDays'];
            }

            if ($locked->job_type === JobType::Milk && (isset($payload['mother']) || isset($payload['milkLitres']))) {
                $detail = $locked->milkDetail;
                if ($detail !== null && isset($payload['mother'])) {
                    $detail->motherParty->fill([
                        'name' => $this->normaliseOptionalText($payload['mother']['name'] ?? null) ?? 'Unknown',
                        'phone' => $this->normaliseOptionalText($payload['mother']['phone'] ?? null) ?? '',
                        'phone_secondary' => $this->normaliseOptionalText($payload['mother']['secondaryPhone'] ?? null),
                    ])->save();
                }
                if ($detail !== null && isset($payload['milkLitres'])) {
                    $detail->milk_litres = $payload['milkLitres'];
                    $detail->save();
                }
            }

            $locked->save();

            if (isset($payload['serviceAreas'])) {
                $locked->serviceAreas()->sync(
                    $this->jobs->serviceAreaIds(array_values($payload['serviceAreas'])),
                );
            }

            $this->purgeFutureNewOccurrences($locked);

            Log::info('Job recurrence updated.', [
                'recurrence_id' => $locked->id,
                'user_id' => $actor->id,
            ]);
        });

        $fresh = $recurrence->fresh();
        if ($fresh !== null && $fresh->is_active) {
            $this->materializer->materialize($fresh, $actor);
        }

        return $this->find($recurrence->fresh() ?? $recurrence);
    }

    public function pause(User $actor, JobRecurrence $recurrence): JobRecurrence
    {
        $recurrence->fill(['is_active' => false])->save();
        Log::info('Job recurrence paused.', [
            'recurrence_id' => $recurrence->id,
            'user_id' => $actor->id,
        ]);

        return $this->find($recurrence);
    }

    public function resume(User $actor, JobRecurrence $recurrence): JobRecurrence
    {
        $recurrence->fill(['is_active' => true])->save();
        $this->materializer->materialize($recurrence, $actor);
        Log::info('Job recurrence resumed.', [
            'recurrence_id' => $recurrence->id,
            'user_id' => $actor->id,
        ]);

        return $this->find($recurrence);
    }

    /**
     * Soft-stop: pause and cancel future New (not yet allocated) occurrences.
     */
    public function stop(User $actor, JobRecurrence $recurrence): JobRecurrence
    {
        return DB::transaction(function () use ($actor, $recurrence): JobRecurrence {
            $locked = JobRecurrence::query()->whereKey($recurrence->id)->lockForUpdate()->firstOrFail();
            $locked->fill(['is_active' => false])->save();
            $this->cancelFutureNewOccurrences($actor, $locked);
            Log::info('Job recurrence stopped.', [
                'recurrence_id' => $locked->id,
                'user_id' => $actor->id,
            ]);

            return $this->find($locked);
        });
    }

    public function materializeAll(): int
    {
        $created = 0;
        JobRecurrence::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (JobRecurrence $recurrence) use (&$created): void {
                $created += count($this->materializer->materialize($recurrence));
            });

        return $created;
    }

    private function purgeFutureNewOccurrences(JobRecurrence $recurrence): void
    {
        $today = now($recurrence->timezone ?: 'Europe/London')->toDateString();

        $requestIds = DeliveryRequest::query()
            ->where('recurrence_id', $recurrence->id)
            ->where('status', JobStatus::New)
            ->whereDate('occurrence_date', '>=', $today)
            ->pluck('id');

        if ($requestIds->isEmpty()) {
            return;
        }

        DeliveryLeg::query()->whereIn('request_id', $requestIds)->delete();
        DeliveryRequest::query()->whereIn('id', $requestIds)->delete();
    }

    private function cancelFutureNewOccurrences(User $actor, JobRecurrence $recurrence): void
    {
        $today = now($recurrence->timezone ?: 'Europe/London')->toDateString();

        DeliveryRequest::query()
            ->where('recurrence_id', $recurrence->id)
            ->where('status', JobStatus::New)
            ->whereDate('occurrence_date', '>=', $today)
            ->orderBy('id')
            ->each(function (DeliveryRequest $request) use ($actor): void {
                $leg = $request->legs()->first();
                if ($leg === null) {
                    return;
                }

                $this->jobs->cancel($actor, $leg, [
                    'reason' => 'Recurring series stopped',
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSchedule(array $payload): void
    {
        $frequency = RecurrenceFrequency::tryFrom((string) ($payload['frequency'] ?? ''));
        if ($frequency === null) {
            throw ValidationException::withMessages([
                'frequency' => ['Choose daily or weekly.'],
            ]);
        }

        if ($frequency !== RecurrenceFrequency::Weekly) {
            return;
        }

        $weekdays = array_values(array_filter(
            array_map('intval', $payload['byWeekday'] ?? []),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        ));

        if ($weekdays !== []) {
            return;
        }

        throw ValidationException::withMessages([
            'byWeekday' => ['Choose at least one weekday for a weekly series.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>|null
     */
    private function normaliseWeekdays(array $payload): ?array
    {
        $frequency = RecurrenceFrequency::tryFrom((string) ($payload['frequency'] ?? ''));
        if ($frequency !== RecurrenceFrequency::Weekly) {
            return null;
        }

        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', $payload['byWeekday'] ?? []),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        )));
        sort($weekdays);

        return $weekdays;
    }

    private function normaliseTimeOfDay(mixed $value): string
    {
        $time = is_string($value) ? trim($value) : '';
        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time.':00';
        }

        return $time;
    }

    private function normaliseOptionalText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function listRelations(): array
    {
        return [
            'senderParty',
            'collectionPlace',
            'deliveryPlace',
            'milkDetail.motherParty',
            'serviceAreas',
            'createdBy',
            'occurrences' => static function ($query): void {
                $query->orderBy('occurrence_date')->limit(40);
            },
        ];
    }
}
