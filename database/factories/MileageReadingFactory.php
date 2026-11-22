<?php

namespace Database\Factories;

use App\Enums\MileageReadingKind;
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
            'operational_shift_id' => OperationalShift::factory(),
            'kind' => MileageReadingKind::End,
            'mileage' => fake()->numberBetween(1_000, 50_000),
            'reason' => null,
            'recorded_at' => now(),
            'recorded_by_user_id' => User::factory(),
        ];
    }

    public function start(): static
    {
        return $this->state(fn (): array => [
            'kind' => MileageReadingKind::Start,
        ]);
    }

    public function end(): static
    {
        return $this->state(fn (): array => [
            'kind' => MileageReadingKind::End,
        ]);
    }
}
