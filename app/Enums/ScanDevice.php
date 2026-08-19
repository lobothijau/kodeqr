<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanDevice: string
{
    case Mobile = 'mobile';
    case Desktop = 'desktop';
    case Tablet = 'tablet';
    case Other = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
