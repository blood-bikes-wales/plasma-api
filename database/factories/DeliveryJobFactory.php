<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\DeliveryJob;
use App\Models\DeliveryRequest;
use App\Models\JobContentType;
use App\Models\MilkJobDetail;
use App\Models\Party;
use App\Models\Place;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Builds a standalone request + single leg (shared id) and returns the leg.
 *
 * @extends Factory<DeliveryJob>
 */
class DeliveryJobFactory extends Factory
{
    protected $model = DeliveryJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => JobStatus::New,
            'collection_place_id' => Place::factory(),
            'delivery_place_id' => Place::factory(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function getRawAttributes(?Model $parent): array
    {
        // Unused for create path — create() builds request+leg explicitly.
        return parent::getRawAttributes($parent);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, DeliveryJob>|DeliveryJob
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! is_array($attributes)) {
            return parent::create($attributes, $parent);
        }

        $count = $this->count;
        if ($count === null || $count === 1) {
            return $this->createStandalone($attributes);
        }

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->createStandalone($attributes);
        }

        return new Collection($items);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createStandalone(array $attributes): DeliveryJob
    {
        $merged = array_merge(
            [
                'reference' => 'JB-'.fake()->unique()->numberBetween(1000, 9999),
                'status' => JobStatus::New,
                'job_type' => JobType::Standard,
                'is_bereavement' => false,
                'sender_name' => fake()->name(),
                'sender_phone' => '029 2074 7747',
                'sender_organisation' => 'University Hospital of Wales',
                'contents' => 'Blood samples',
                'created_by_user_id' => User::factory(),
                'collection_place_id' => Place::factory(),
                'delivery_place_id' => Place::factory(),
            ],
            $this->applyStates($attributes),
        );

        // Legacy test attribute: parent_job_id meant "leg under this parent".
        if (isset($merged['parent_job_id']) && ! isset($merged['_for_request_id'])) {
            $merged['_for_request_id'] = $merged['parent_job_id'];
            unset($merged['parent_job_id']);
        }

        if (isset($merged['request_id']) && ! isset($merged['_for_request_id'])) {
            $merged['_for_request_id'] = $merged['request_id'];
            unset($merged['request_id']);
        }

        if (isset($merged['_for_request_id'])) {
            return $this->createLegUnderRequest($merged);
        }

        $sharedId = (string) Str::uuid();
        $jobType = $merged['job_type'] instanceof JobType
            ? $merged['job_type']
            : JobType::from((string) $merged['job_type']);
        $status = $merged['status'] instanceof JobStatus
            ? $merged['status']
            : JobStatus::from((string) $merged['status']);

        $userId = $merged['created_by_user_id'];
        if ($userId instanceof User) {
            $userId = $userId->id;
        }
        if ($userId instanceof Factory) {
            $userId = $userId->create()->id;
        }

        $collectionPlaceId = $this->resolvePlaceId($merged['collection_place_id']);
        $deliveryPlaceId = $this->resolvePlaceId($merged['delivery_place_id']);

        $sender = Party::query()->create([
            'name' => $merged['sender_name'],
            'phone' => $merged['sender_phone'],
            'organisation' => $merged['sender_organisation'] ?? null,
        ]);

        $requestAttrs = [
            'id' => $sharedId,
            'reference' => $merged['reference'],
            'status' => $status,
            'job_type' => $jobType,
            'is_bereavement' => (bool) $merged['is_bereavement'],
            'sender_party_id' => $sender->id,
            'content_type_id' => JobContentType::query()
                ->where('name', $merged['contents'] ?? 'Blood samples')
                ->value('id'),
            'content_label_snapshot' => $merged['contents'] ?? 'Blood samples',
            'scheduled_collection_at' => $merged['scheduled_collection_at'] ?? null,
            'created_by_user_id' => $userId,
        ];
        if (array_key_exists('created_at', $merged)) {
            $requestAttrs['created_at'] = $merged['created_at'];
        }
        if (array_key_exists('updated_at', $merged)) {
            $requestAttrs['updated_at'] = $merged['updated_at'];
        }

        /** @var DeliveryRequest $request */
        $request = new DeliveryRequest;
        $request->timestamps = false;
        if (! array_key_exists('created_at', $requestAttrs)) {
            $requestAttrs['created_at'] = now();
        }
        if (! array_key_exists('updated_at', $requestAttrs)) {
            $requestAttrs['updated_at'] = now();
        }
        $request->forceFill($requestAttrs);
        $request->save();

        $area = ServiceArea::query()->firstOrCreate(
            ['code' => 'E'],
            ['full_name' => 'East'],
        );
        $request->serviceAreas()->sync([$area->id]);

        if ($jobType === JobType::Milk) {
            $mother = Party::query()->create([
                'name' => $merged['mother_name'] ?? 'Megan Evans',
                'phone' => $merged['mother_phone'] ?? '07700 900101',
                'phone_secondary' => $merged['mother_phone_secondary'] ?? '029 2018 0000',
            ]);

            MilkJobDetail::query()->create([
                'delivery_request_id' => $request->id,
                'milk_litres' => $merged['milk_litres'] ?? 2.5,
                'mother_party_id' => $mother->id,
            ]);
        }

        return $this->insertLeg($sharedId, $request->id, 1, $merged['reference'], $status, $merged, $collectionPlaceId, $deliveryPlaceId);
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function createLegUnderRequest(array $merged): DeliveryJob
    {
        $requestId = (string) $merged['_for_request_id'];
        /** @var DeliveryRequest $request */
        $request = DeliveryRequest::query()->findOrFail($requestId);

        // Convert a former standalone (shared request/leg id) into a multi-leg request.
        // Do not delete an already-built L1 when tests subsequently attach L2+.
        if ($request->legs()->count() === 1 && (int) ($merged['leg_number'] ?? 0) >= 1) {
            $sole = $request->legs()->first();
            if ($sole !== null && $sole->id === $request->id) {
                $sole->delete();
            }
        }

        $status = $merged['status'] instanceof JobStatus
            ? $merged['status']
            : JobStatus::from((string) ($merged['status'] ?? JobStatus::New->value));
        $legNumber = (int) ($merged['leg_number'] ?? ($request->legs()->count() + 1));
        $reference = (string) ($merged['reference'] ?? sprintf('%s-L%d', $request->reference, $legNumber));
        $collectionPlaceId = $this->resolvePlaceId($merged['collection_place_id'] ?? Place::factory());
        $deliveryPlaceId = $this->resolvePlaceId($merged['delivery_place_id'] ?? Place::factory());

        if ($merged['scheduled_collection_at'] ?? null) {
            $request->forceFill(['scheduled_collection_at' => $merged['scheduled_collection_at']])->save();
        }

        return $this->insertLeg(
            (string) Str::uuid(),
            $requestId,
            $legNumber,
            $reference,
            $status,
            $merged,
            $collectionPlaceId,
            $deliveryPlaceId,
        );
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function insertLeg(
        string $id,
        string $requestId,
        int $legNumber,
        string $reference,
        JobStatus $status,
        array $merged,
        string $collectionPlaceId,
        string $deliveryPlaceId,
    ): DeliveryJob {
        $legAttrs = [
            'id' => $id,
            'request_id' => $requestId,
            'leg_number' => $legNumber,
            'reference' => $reference,
            'status' => $status,
            'collection_place_id' => $collectionPlaceId,
            'delivery_place_id' => $deliveryPlaceId,
            'collection_notes' => $merged['collection_notes'] ?? null,
            'delivery_notes' => $merged['delivery_notes'] ?? null,
            'estimated_collection_at' => $merged['estimated_collection_at'] ?? null,
            'estimated_delivery_at' => $merged['estimated_delivery_at'] ?? null,
            'planned_arrival_at' => $merged['planned_arrival_at'] ?? null,
            'operational_shift_id' => $merged['operational_shift_id'] ?? null,
            'allocated_at' => $merged['allocated_at'] ?? null,
            'contents_confirmed' => $merged['contents_confirmed'] ?? null,
            'receipt_number' => $merged['receipt_number'] ?? null,
            'collected_at' => $merged['collected_at'] ?? null,
            'recipient' => $merged['recipient'] ?? null,
            'no_recipient' => $merged['no_recipient'] ?? false,
            'delivered_at' => $merged['delivered_at'] ?? null,
            'cancellation_reason' => $merged['cancellation_reason'] ?? null,
            'cancelled_at' => $merged['cancelled_at'] ?? null,
        ];
        if (array_key_exists('created_at', $merged)) {
            $legAttrs['created_at'] = $merged['created_at'];
        }
        if (array_key_exists('updated_at', $merged)) {
            $legAttrs['updated_at'] = $merged['updated_at'];
        }

        if (! array_key_exists('created_at', $legAttrs)) {
            $legAttrs['created_at'] = now();
        }
        if (! array_key_exists('updated_at', $legAttrs)) {
            $legAttrs['updated_at'] = now();
        }

        /** @var DeliveryJob $leg */
        $leg = new DeliveryJob;
        $leg->timestamps = false;
        $leg->forceFill($legAttrs);
        $leg->save();

        // Keep request status in sync without bumping its updated_at (tests pin finish times).
        DeliveryRequest::withoutTimestamps(function () use ($requestId, $status, $merged): void {
            $attrs = ['status' => $status->value];
            if (array_key_exists('updated_at', $merged)) {
                $attrs['updated_at'] = $merged['updated_at'];
            }
            DeliveryRequest::query()->whereKey($requestId)->update($attrs);
        });

        return $leg->load([
            'collectionPlace',
            'deliveryPlace',
            'request.senderParty',
            'request.milkDetail.motherParty',
            'request.serviceAreas',
            'request.legs',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyStates(array $attributes): array
    {
        $model = $this->newModel([]);
        $merged = [];

        foreach ($this->states as $state) {
            $result = is_callable($state) ? $state($merged, $model) : $state;
            $merged = array_merge($merged, $result);
        }

        return array_merge($merged, $attributes);
    }

    private function resolvePlaceId(mixed $value): string
    {
        if ($value instanceof Place) {
            return $value->id;
        }

        if ($value instanceof Factory) {
            return $value->create()->id;
        }

        return (string) $value;
    }

    public function milk(float $litres = 2.5): static
    {
        return $this->state([
            'job_type' => JobType::Milk,
            'milk_litres' => $litres,
            'mother_name' => 'Megan Evans',
            'mother_phone' => '07700 900101',
            'mother_phone_secondary' => '029 2018 0000',
        ]);
    }

    public function bereavement(): static
    {
        return $this->milk()->state(['is_bereavement' => true]);
    }

    public function allocated(): static
    {
        return $this->state(['status' => JobStatus::Allocated]);
    }

    public function collected(): static
    {
        return $this->state(['status' => JobStatus::Collected]);
    }

    public function delivered(): static
    {
        return $this->state(['status' => JobStatus::Delivered]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => JobStatus::Cancelled]);
    }

    /**
     * Create an additional hop under an existing request (for building relays in tests).
     * Does not create a new request/party.
     */
    public function forRequest(DeliveryRequest|DeliveryJob $requestOrLeg, int $legNumber): static
    {
        $requestId = $requestOrLeg instanceof DeliveryRequest
            ? $requestOrLeg->id
            : $requestOrLeg->request_id;

        $referenceBase = $requestOrLeg instanceof DeliveryRequest
            ? $requestOrLeg->reference
            : ($requestOrLeg->request?->reference ?? $requestOrLeg->reference);

        return $this->state([
            '_for_request_id' => $requestId,
            '_for_request_reference' => $referenceBase,
            'leg_number' => $legNumber,
            'reference' => sprintf('%s-L%d', $referenceBase, $legNumber),
        ]);
    }
}
