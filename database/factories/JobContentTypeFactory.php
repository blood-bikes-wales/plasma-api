<?php

namespace Database\Factories;

use App\Models\JobContentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobContentType>
 */
class JobContentTypeFactory extends Factory
{
    protected $model = JobContentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'active' => true,
            'sort_order' => fake()->numberBetween(100, 1000),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
