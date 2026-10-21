<?php

namespace Database\Factories;

use App\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceArea>
 */
class ServiceAreaFactory extends Factory
{
    protected $model = ServiceArea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'full_name' => fake()->unique()->words(2, true),
        ];
    }
}
