<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Models\QrCode;
use App\Models\User;
use App\Services\SlugGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrCode>
 */
class QrCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'user_id' => User::factory(),
            'slug' => $this->slug(),
            'type' => QrCodeType::Url,
            'destination' => ['url' => $url, 'dest_url' => $url],
            'style' => null,
            'status' => QrCodeStatus::Active,
            'scan_count' => 0,
            'last_scanned_at' => null,
        ];
    }

    public function status(QrCodeStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    /**
     * Generated here rather than through SlugGenerator so a factory call stays free of
     * a uniqueness SELECT; the alphabet is shared so the two can never drift.
     */
    private function slug(): string
    {
        $alphabet = SlugGenerator::ALPHABET;
        $slug = '';

        for ($i = 0; $i < SlugGenerator::LENGTH; $i++) {
            $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $slug;
    }
}
