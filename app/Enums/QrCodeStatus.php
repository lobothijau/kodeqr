<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every status maps to exactly one scanner-facing page (constraint 8). There is
 * no grace status: an expired owner's codes stay `active` and are rendered with the
 * splash, driven by the owner's plan rather than by per-code state.
 */
enum QrCodeStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Blocked = 'blocked';
    case OverQuota = 'over_quota';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
