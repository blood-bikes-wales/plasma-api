<?php

namespace Database\Factories;

use App\Models\Rider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rider>
 */
class RiderFactory extends Factory
{
    protected $model = Rider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => fake()->unique()->numberBetween(100_000, 999_999),
            'name' => fake()->name(),
        ];
    }
}
