<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QrEye;
use App\Enums\QrFormat;
use App\Enums\QrGradientType;
use App\Enums\QrPattern;
use InvalidArgumentException;

/**
 * Everything about how a code is drawn, and nothing about what it encodes.
 *
 * The split is deliberate: {@see QrRenderer} builds the encoded string itself from
 * the code's slug, so there is no field here — and no field anywhere on the request
 * path — that could carry a destination URL into the image (constraint 4).
 *
 * Reading is total. Unknown or malformed values in `qr_codes.style` fall back to the
 * default rather than throwing, because a style column written by an older release
 * must never turn an owner's existing code into an error page. Contrast is the one
 * thing that is not silently repaired: an unscannable code is worse than a refusal,
 * so it is reported through {@see self::contrastFailures()} and left to the caller.
 */
final class RenderSpec
{
    public const MIN_SIZE = 240;

    public const MAX_SIZE = 2048;

    public const DEFAULT_SIZE = 512;

    /**
     * WCAG relative-luminance distance required between the background and every
     * ink colour. Chosen against the scanner, not the designer: below roughly this
     * a phone camera in shop lighting starts failing on the darker module edges.
     */
    public const MIN_LUMINANCE_DELTA = 0.4;

    private const DEFAULT_FOREGROUND = '#18181b';

    private const DEFAULT_BACKGROUND = '#ffffff';

    public function __construct(
        public readonly QrFormat $format = QrFormat::Svg,
        public readonly int $size = self::DEFAULT_SIZE,
        public readonly QrPattern $pattern = QrPattern::Square,
        public readonly QrEye $eye = QrEye::Auto,
        public readonly string $foreground = self::DEFAULT_FOREGROUND,
        public readonly string $background = self::DEFAULT_BACKGROUND,
        public readonly ?string $gradientTo = null,
        public readonly QrGradientType $gradientType = QrGradientType::Diagonal,
        public readonly ?string $eyeColor = null,
        public readonly ?string $logoPath = null,
    ) {}

    /**
     * Build from a persisted `qr_codes.style` array plus the caller's format and
     * size, which are request concerns rather than stored ones.
     *
     * @param  array<string, mixed>  $style
     */
    public static function fromStyle(
        array $style,
        QrFormat $format = QrFormat::Svg,
        ?int $size = null,
        ?string $logoPath = null,
    ): self {
        return new self(
            format: $format,
            size: self::clampSize($size ?? self::readInt($style, 'size') ?? self::DEFAULT_SIZE),
            pattern: self::readEnum(QrPattern::class, $style, 'pattern') ?? QrPattern::Square,
            eye: self::readEnum(QrEye::class, $style, 'eye') ?? QrEye::Auto,
            foreground: self::readColor($style, 'foreground') ?? self::DEFAULT_FOREGROUND,
            background: self::readColor($style, 'background') ?? self::DEFAULT_BACKGROUND,
            gradientTo: self::readColor($style, 'gradient_to'),
            gradientType: self::readEnum(QrGradientType::class, $style, 'gradient_type') ?? QrGradientType::Diagonal,
            eyeColor: self::readColor($style, 'eye_color'),
            logoPath: $logoPath,
        );
    }

    public function hasGradient(): bool
    {
        return $this->gradientTo !== null;
    }

    public function hasLogo(): bool
    {
        return $this->logoPath !== null;
    }

    /**
     * Which ink colours sit too close to the background to survive a phone camera.
     *
     * Returned rather than thrown so a form can report every offending swatch at
     * once; {@see QrRenderer::render()} treats a non-empty result as fatal.
     *
     * @return array<int, string> style keys: foreground, gradient_to, eye_color
     */
    public function contrastFailures(): array
    {
        $background = self::luminance($this->background);
        $failures = [];

        $fails = fn (float $luminance): bool => abs($luminance - $background) < self::MIN_LUMINANCE_DELTA;

        if ($fails(self::luminance($this->foreground))) {
            $failures[] = 'foreground';
        }

        /*
         * A gradient is checked along its length, not just at its ends. Relative
         * luminance is convex in sRGB, so two stops can both clear the threshold
         * while the colours between them dip below it — #bcad2d to #7cb3e2 on black
         * ends at 0.408 and 0.420 and passes through 0.399. Measured, that dip is
         * small enough to still decode, so this is about the guard meaning what it
         * says rather than a known unscannable output.
         */
        if ($this->gradientTo !== null) {
            foreach ([0.25, 0.5, 0.75] as $position) {
                if ($fails(self::luminance(self::mix($this->foreground, $this->gradientTo, $position)))) {
                    $failures[] = 'gradient_to';

                    break;
                }
            }

            if (! in_array('gradient_to', $failures, true) && $fails(self::luminance($this->gradientTo))) {
                $failures[] = 'gradient_to';
            }
        }

        if ($this->eyeColor !== null && $fails(self::luminance($this->eyeColor))) {
            $failures[] = 'eye_color';
        }

        return $failures;
    }

    /**
     * Linear interpolation in sRGB, which is how both the SVG renderer and Imagick
     * interpolate a gradient — so this samples the colours actually drawn, not a
     * perceptually-corrected version of them.
     */
    private static function mix(string $from, string $to, float $position): string
    {
        [$r1, $g1, $b1] = self::channels($from);
        [$r2, $g2, $b2] = self::channels($to);

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($r1 + ($r2 - $r1) * $position) * 255),
            (int) round(($g1 + ($g2 - $g1) * $position) * 255),
            (int) round(($b1 + ($b2 - $b1) * $position) * 255),
        );
    }

    /**
     * WCAG 2.x relative luminance, 0 (black) to 1 (white).
     */
    private static function luminance(string $hex): float
    {
        $linear = static fn (float $channel): float => $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;

        [$r, $g, $b] = self::channels($hex);

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }

    /**
     * @return array{float, float, float} each 0.0–1.0
     */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    public static function isValidColor(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    public static function clampSize(int $size): int
    {
        return max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
    }

    /**
     * @param  array<string, mixed>  $style
     */
    private static function readColor(array $style, string $key): ?string
    {
        $value = $style[$key] ?? null;

        return is_string($value) && self::isValidColor($value) ? strtolower($value) : null;
    }

    /**
     * @param  array<string, mixed>  $style
     */
    private static function readInt(array $style, string $key): ?int
    {
        $value = $style[$key] ?? null;

        // Numeric strings count. A JSON round-trip through a browser form returns
        // "1024", and discarding that would silently revert an owner's saved export
        // size to the default — the value is clamped either way.
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  array<string, mixed>  $style
     * @return T|null
     */
    private static function readEnum(string $enum, array $style, string $key)
    {
        $value = $style[$key] ?? null;

        return is_string($value) ? $enum::tryFrom($value) : null;
    }

    /**
     * Guard for callers building a spec by hand rather than from stored style.
     *
     * All four colour fields, not just the two required ones: a malformed hex slips
     * through `hexdec()` as a deprecation notice and a channel value of 0, so an
     * unvalidated `#gggggg` is scored as near-black, sails through the contrast
     * gate, and is then painted as some arbitrary other colour. The two optional
     * fields are exactly the ones a hand-built spec is most likely to carry.
     */
    public function assertValid(): void
    {
        $colors = array_filter([
            'foreground' => $this->foreground,
            'background' => $this->background,
            'gradient_to' => $this->gradientTo,
            'eye_color' => $this->eyeColor,
        ]);

        foreach ($colors as $key => $color) {
            if (! self::isValidColor($color)) {
                throw new InvalidArgumentException(__('qr.color_invalid', ['field' => __('qr.style.'.$key)]));
            }
        }
    }
}
