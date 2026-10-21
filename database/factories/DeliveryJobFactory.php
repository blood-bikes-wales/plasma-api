<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\Place;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryJob>
 */
class DeliveryJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'JB-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => JobStatus::New,
            'sender_name' => fake()->name(),
            'sender_phone' => '029 2074 7747',
            'sender_organisation' => 'University Hospital of Wales',
            'collection_place_id' => Place::factory(),
            'delivery_place_id' => Place::factory(),
            'contents' => 'Blood samples',
            'created_by_user_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DeliveryJob $job): void {
            if ($job->serviceAreas()->exists()) {
                return;
            }

            $area = ServiceArea::query()->firstOrCreate(
                ['code' => 'E'],
                ['full_name' => 'East'],
            );

            $job->serviceAreas()->sync([$area->id]);
        });
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
}
