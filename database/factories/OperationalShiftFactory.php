<?php

namespace Database\Factories;

use App\Enums\BikeKeptAt;
use App\Enums\MileageReadingKind;
use App\Models\Bike;
use App\Models\MileageReading;
use App\Models\OperationalShift;
use App\Models\Rider;
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
        return [
            'rider_id' => Rider::factory(),
            'bike_id' => Bike::factory(),
            'logged_on_at' => now(),
            'logged_on_by_user_id' => User::factory(),
            'logged_off_at' => null,
            'faults' => null,
            'bike_kept_at' => BikeKeptAt::Lockup,
            'logged_off_by_user_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (OperationalShift $shift): void {
            if ($shift->bike_id === null) {
                return;
            }

            $hasStart = $shift->mileageReadings()
                ->where('kind', MileageReadingKind::Start)
                ->exists();

            if ($hasStart) {
                return;
            }

            MileageReading::query()->create([
                'operational_shift_id' => $shift->id,
                'kind' => MileageReadingKind::Start,
                'mileage' => fake()->numberBetween(1_000, 50_000),
                'reason' => null,
                'recorded_at' => $shift->logged_on_at,
                'recorded_by_user_id' => $shift->logged_on_by_user_id,
            ]);
        });
    }

    /**
     * Record a specific start odometer (and optional variance reason) for the shift.
     */
    public function withStartMileage(int $mileage, ?string $reason = null): static
    {
        return $this->afterCreating(function (OperationalShift $shift) use ($mileage, $reason): void {
            if ($shift->bike_id === null) {
                return;
            }

            MileageReading::query()->updateOrCreate(
                [
                    'operational_shift_id' => $shift->id,
                    'kind' => MileageReadingKind::Start,
                ],
                [
                    'mileage' => $mileage,
                    'reason' => $reason,
                    'recorded_at' => $shift->logged_on_at,
                    'recorded_by_user_id' => $shift->logged_on_by_user_id,
                ],
            );
        });
    }

    /**
     * Indicate that the rider was logged on before a bike was chosen.
     */
    public function withoutBike(): static
    {
        return $this->state(fn (): array => [
            'bike_id' => null,
        ]);
    }

    /**
     * Indicate that the shift has already been logged off.
     */
    public function loggedOff(?int $endMileage = null, ?string $endReason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'logged_off_at' => ($attributes['logged_on_at'] ?? now())->copy()->addHour(),
            'logged_off_by_user_id' => User::factory(),
        ])->afterCreating(function (OperationalShift $shift) use ($endMileage, $endReason): void {
            if ($shift->bike_id === null) {
                return;
            }

            $start = $shift->mileageReadings()
                ->where('kind', MileageReadingKind::Start)
                ->first();

            $resolvedEnd = $endMileage;
            if ($resolvedEnd === null) {
                $startMileage = $start?->mileage ?? fake()->numberBetween(1_000, 50_000);
                $resolvedEnd = $startMileage + fake()->numberBetween(1, 80);
            }

            MileageReading::query()->updateOrCreate(
                [
                    'operational_shift_id' => $shift->id,
                    'kind' => MileageReadingKind::End,
                ],
                [
                    'mileage' => $resolvedEnd,
                    'reason' => $endReason,
                    'recorded_at' => $shift->logged_off_at,
                    'recorded_by_user_id' => $shift->logged_off_by_user_id ?? $shift->logged_on_by_user_id,
                ],
            );
        });
    }
}
