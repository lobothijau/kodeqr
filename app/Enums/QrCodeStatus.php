<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every status maps to exactly one scanner-facing page (constraint 8).
 */
enum QrCodeStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Grace = 'grace';
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
