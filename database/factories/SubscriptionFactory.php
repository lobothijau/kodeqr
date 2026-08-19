<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Package;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => Plan::Regular,
            'package' => Package::ThreeMonths,
            'starts_at' => now(),
            'ends_at' => now()->addMonths(3),
            'status' => SubscriptionStatus::Active,
        ];
    }

    /**
     * Expired. Codes keep redirecting behind the splash; editing and analytics stop.
     */
    public function lapsed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->subMonths(4),
            'ends_at' => now()->subDays(5),
            'status' => SubscriptionStatus::Lapsed,
        ]);
    }
}
