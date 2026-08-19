<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Prepaid durations. There is no monthly package and no auto-renewal — see
 * documentation/billing.md.
 */
enum Package: string
{
    case ThreeMonths = 'three_months';
    case SixMonths = 'six_months';
    case TwelveMonths = 'twelve_months';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function months(): int
    {
        return match ($this) {
            self::ThreeMonths => 3,
            self::SixMonths => 6,
            self::TwelveMonths => 12,
        };
    }

    public function addTo(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->addMonths($this->months());
    }
}
