<?php

namespace Database\Factories;

use App\Models\Bike;
use App\Models\MileageReading;
use App\Models\OperationalShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MileageReading>
 */
class MileageReadingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bike_id' => Bike::factory(),
            'operational_shift_id' => OperationalShift::factory(),
            'mileage' => fake()->numberBetween(1_000, 50_000),
            'reason' => null,
            'recorded_at' => now(),
            'recorded_by_user_id' => User::factory(),
        ];
    }
}
