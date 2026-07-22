<?php

namespace Database\Factories;

use App\Enums\AuthFailureReason;
use App\Models\AuthAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthAuditLog>
 */
class AuthAuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->safeEmail(),
            'hosted_domain' => fake()->domainName(),
            'reason' => fake()->randomElement(AuthFailureReason::cases()),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
