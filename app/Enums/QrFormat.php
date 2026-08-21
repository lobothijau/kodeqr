<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SVG is canonical: it is pure PHP, resolution-independent, and the format a print
 * shop can actually scale. PNG is the rasterisation of the same path geometry and
 * needs ext-imagick.
 */
enum QrFormat: string
{
    case Png = 'png';
    case Svg = 'svg';

    public function mimeType(): string
    {
        return match ($this) {
            self::Png => 'image/png',
            self::Svg => 'image/svg+xml',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
