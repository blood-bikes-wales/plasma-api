<?php

namespace Database\Factories;

use App\Models\OutboundPostcodeArea;
use App\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundPostcodeArea>
 */
class OutboundPostcodeAreaFactory extends Factory
{
    protected $model = OutboundPostcodeArea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $postcode = strtoupper(fake()->unique()->bothify('??##'));

        return [
            'outbound_postcode' => $postcode,
            'service_area_id' => fn (): string => ServiceArea::query()->firstOrCreate(
                ['code' => 'E'],
                ['full_name' => 'East'],
            )->id,
            'sort_order' => fake()->unique()->numberBetween(1, 100_000),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'active' => false,
        ]);
    }
}
