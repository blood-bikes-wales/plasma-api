<?php

namespace Database\Factories;

use App\Models\Bike;
use App\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bike>
 */
class BikeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration' => strtoupper(fake()->unique()->bothify('??## ???')),
            'service_area_id' => fn (): string => ServiceArea::query()->firstOrCreate(
                ['code' => 'E'],
                ['full_name' => 'East'],
            )->id,
            'initial_mileage' => fake()->numberBetween(1_000, 50_000),
            'retired_at' => null,
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'retired_at' => now(),
        ]);
    }
}
