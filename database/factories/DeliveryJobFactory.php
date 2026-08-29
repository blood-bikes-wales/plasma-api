<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Models\DeliveryJob;
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
            'collection_place_id' => 'ChIJCollectionPlaceId0001',
            'collection_address' => 'University Hospital of Wales, Cardiff CF14 4XW',
            'collection_latitude' => 51.5068,
            'collection_longitude' => -3.1900,
            'delivery_place_id' => 'ChIJDeliveryPlaceId0001',
            'delivery_address' => 'Royal Glamorgan Hospital, Llantrisant CF72 8XR',
            'delivery_latitude' => 51.5470,
            'delivery_longitude' => -3.3910,
            'contents' => 'Blood samples',
            'service_areas' => ['South'],
            'created_by_user_id' => User::factory(),
        ];
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
