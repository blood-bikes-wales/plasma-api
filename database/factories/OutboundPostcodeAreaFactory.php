<?php

namespace Database\Factories;

use App\Models\OutboundPostcodeArea;
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
            'area_code' => 'E',
            'area_full_name' => 'East',
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
