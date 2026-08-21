<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Direction of a two-stop foreground gradient. Persisted in `qr_codes.style` —
 * see the note on {@see QrPattern}.
 */
enum QrGradientType: string
{
    case Vertical = 'vertical';
    case Horizontal = 'horizontal';
    case Diagonal = 'diagonal';
    case InverseDiagonal = 'inverse_diagonal';
    case Radial = 'radial';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
