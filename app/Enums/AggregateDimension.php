<?php

declare(strict_types=1);

namespace App\Enums;

enum AggregateDimension: string
{
    case City = 'city';
    case Device = 'device';
    case Os = 'os';
    case Hour = 'hour';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
