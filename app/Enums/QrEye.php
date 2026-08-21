<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three finder patterns — the squares a camera locks onto before it reads
 * anything. `Auto` keeps them in step with the module shape when the owner changes
 * pattern without touching this control.
 *
 * bacon also ships a `PointyEye`. It is deliberately absent: measured against
 * zxing-cpp (the engine behind Android and Google Lens) it decoded 0 times out of
 * 20 on every one of the five patterns, at every size. Its points break the
 * 1:1:3:1:1 ratio a decoder scans for, so a code using it is not a styled code, it
 * is a broken one. Do not add it back without re-running that measurement.
 *
 * Persisted in `qr_codes.style` — see the note on {@see QrPattern}.
 */
enum QrEye: string
{
    case Auto = 'auto';
    case Square = 'square';
    case Circle = 'circle';
    case CircleInSquare = 'circle_in_square';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
