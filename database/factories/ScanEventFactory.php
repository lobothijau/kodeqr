<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScanDevice;
use App\Models\QrCode;
use App\Models\ScanEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScanEvent>
 */
class ScanEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'qr_code_id' => QrCode::factory(),
            'event_uuid' => (string) Str::ulid(),
            'occurred_at' => now(),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'country' => 'ID',
            'region' => 'Jakarta',
            'city' => 'Jakarta',
            'device' => ScanDevice::Mobile,
            'os' => 'Android',
            'browser' => 'Chrome',
            'is_unique' => true,
            'is_bot' => false,
            'referer' => null,
        ];
    }

    public function bot(): static
    {
        return $this->state(fn (array $attributes): array => ['is_bot' => true]);
    }
}
