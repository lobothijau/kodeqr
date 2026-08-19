<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrCode>
 */
class QrCodeFactory extends Factory
{
    /**
     * The slug alphabet from CLAUDE.md conventions: 54 chars, excluding 0 1 I L O i l o.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';

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

    private function slug(): string
    {
        $slug = '';

        for ($i = 0; $i < 6; $i++) {
            $slug .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $slug;
    }
}
