<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Module geometry. These five are the shapes bacon/bacon-qr-code draws natively;
 * the exotic patterns the competition offers (blob, leaf, sparkle) need a
 * hand-written ModuleInterface and are logged in docs/BACKLOG.md rather than
 * half-built here.
 *
 * Values are persisted in `qr_codes.style`, so renaming a case rewrites printed
 * paper's appearance on the next export. Add cases; do not rename them.
 */
enum QrPattern: string
{
    case Square = 'square';
    case Rounded = 'rounded';
    case RoundedStrong = 'rounded_strong';
    case Dots = 'dots';
    case DotsSmall = 'dots_small';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
