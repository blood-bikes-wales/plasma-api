<?php

namespace Database\Factories;

use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalShift>
 */
class OperationalShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startMileage = fake()->numberBetween(1_000, 50_000);

        return [
            'rider_id' => fake()->unique()->numberBetween(100_000, 999_999),
            'rider_name' => fake()->name(),
            'bike_id' => Bike::factory(),
            'start_mileage' => $startMileage,
            'mileage_variance_reason' => null,
            'logged_on_at' => now(),
            'logged_on_by_user_id' => User::factory(),
            'logged_off_at' => null,
            'end_mileage' => null,
            'faults' => null,
            'logged_off_by_user_id' => null,
        ];
    }

    /**
     * Indicate that the shift has already been logged off.
     */
    public function loggedOff(): static
    {
        return $this->state(function (array $attributes) {
            $endMileage = $attributes['start_mileage'] + fake()->numberBetween(1, 80);

            return [
                'logged_off_at' => now(),
                'end_mileage' => $endMileage,
                'logged_off_by_user_id' => User::factory(),
            ];
        });
    }
}
