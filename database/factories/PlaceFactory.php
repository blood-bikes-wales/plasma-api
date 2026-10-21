<?php

namespace Database\Factories;

use App\Models\Place;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
{
    protected $model = Place::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'ChIJ'.fake()->unique()->bothify('??????????????????????'),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'latitude' => fake()->latitude(51.4, 53.4),
            'longitude' => fake()->longitude(-4.5, -2.5),
        ];
    }
}
