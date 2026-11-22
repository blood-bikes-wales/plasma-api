<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\DeliveryRequest;
use App\Models\JobContentType;
use App\Models\MilkJobDetail;
use App\Models\Party;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryRequest>
 */
class DeliveryRequestFactory extends Factory
{
    protected $model = DeliveryRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'JB-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => JobStatus::New,
            'job_type' => JobType::Standard,
            'is_bereavement' => false,
            'sender_party_id' => Party::factory(),
            'content_label_snapshot' => 'Blood samples',
            'created_by_user_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DeliveryRequest $request): void {
            $contentTypeId = JobContentType::query()
                ->where('name', $request->content_label_snapshot)
                ->value('id');

            if ($contentTypeId !== null && $request->content_type_id === null) {
                $request->forceFill(['content_type_id' => $contentTypeId])->saveQuietly();
            }

            if ($request->serviceAreas()->exists()) {
                return;
            }

            $area = ServiceArea::query()->firstOrCreate(
                ['code' => 'E'],
                ['full_name' => 'East'],
            );
            $request->serviceAreas()->sync([$area->id]);
        });
    }

    public function milk(float $litres = 2.5): static
    {
        return $this->state([
            'job_type' => JobType::Milk,
        ])->afterCreating(function (DeliveryRequest $request) use ($litres): void {
            if ($request->milkDetail !== null) {
                return;
            }

            $mother = Party::query()->create([
                'name' => 'Megan Evans',
                'phone' => '07700 900101',
                'phone_secondary' => '029 2018 0000',
            ]);

            MilkJobDetail::query()->create([
                'delivery_request_id' => $request->id,
                'milk_litres' => $litres,
                'mother_party_id' => $mother->id,
            ]);
        });
    }

    public function bereavement(): static
    {
        return $this->milk()->state(['is_bereavement' => true]);
    }
}
