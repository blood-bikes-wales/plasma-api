<?php

namespace Database\Factories;

use App\Models\Bike;
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
            'last_recorded_mileage' => fake()->numberBetween(1_000, 50_000),
        ];
    }
}
