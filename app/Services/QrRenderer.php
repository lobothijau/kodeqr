<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QrEye;
use App\Enums\QrFormat;
use App\Enums\QrGradientType;
use App\Enums\QrPattern;
use App\Models\QrCode;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Eye\CompositeEye;
use BaconQrCode\Renderer\Eye\EyeInterface;
use BaconQrCode\Renderer\Eye\SimpleCircleEye;
use BaconQrCode\Renderer\Eye\SquareEye;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Module\DotsModule;
use BaconQrCode\Renderer\Module\ModuleInterface;
use BaconQrCode\Renderer\Module\RoundnessModule;
use BaconQrCode\Renderer\Module\SquareModule;
use BaconQrCode\Renderer\RendererStyle\EyeFill;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\Gradient;
use BaconQrCode\Renderer\RendererStyle\GradientType;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;

/**
 * Draws a code. The encoded string is built here, from the slug, and is not a
 * parameter — there is no argument on this class through which a destination URL
 * could reach the image (constraint 4). The whole dynamic premise dies silently if
 * that ever stops being true, so {@see self::scanUrl()} is the only producer of the
 * payload and it takes a model, not a string.
 */
final class QrRenderer
{
    /**
     * Side of the logo box as a fraction of the code's width. Well under the 20%
     * of AREA the plan allows (this is ~4.8%): error correction has to absorb the
     * knockout, and a logo sized to the ceiling scans from a screen but not from
     * paper at a distance, which is the only test that matters here.
     */
    private const LOGO_SIDE_RATIO = 0.22;

    /**
     * White ring between the logo and the nearest module, as a fraction of width.
     * Without it the decoder reads logo pixels as modules at the boundary.
     */
    private const LOGO_PAD_RATIO = 0.02;

    /**
     * ISO/IEC 18004 mandates a four-module quiet zone, and bacon defaults to it.
     * A tighter margin looks better in a card on screen and is invisible on white
     * paper — the page supplies the missing zone — but the background rect is
     * painted in the owner's colour, so on a dark menu or a photo the code has
     * exactly the margin drawn here and nothing more. Styled codes are the ones
     * that end up on dark backgrounds, so the standard's number wins.
     */
    private const QUIET_ZONE_MODULES = 4;

    /** PNG, JPEG, WebP. Anything else is a delegate waiting to be invoked. */
    private const ALLOWED_LOGO_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    private const MAX_LOGO_DIMENSION = 4096;

    private const IMAGICK_MEMORY_LIMIT = 64 * 1024 * 1024;

    /**
     * The one string a code ever encodes. Not the destination — the destination is
     * looked up at scan time, which is what lets an owner reprint nothing when it
     * changes.
     *
     * Built from configuration, deliberately NOT from `route()`. Laravel roots an
     * absolute URL at the CURRENT REQUEST's scheme and host, so a render served
     * over a staging hostname, a *.laravel.cloud origin URL, or a request carrying
     * an attacker's Host header would encode that host instead of ours — and this
     * output goes on printed paper, where it cannot be corrected afterwards. The
     * host has to be a property of the installation, not of whoever asked.
     */
    public function scanUrl(QrCode $qr): string
    {
        return rtrim((string) config('app.url'), '/').'/x/'.$qr->slug;
    }

    /**
     * @return string raw PNG or SVG bytes
     *
     * @throws InvalidArgumentException when a colour is malformed or too low-contrast to scan
     */
    public function render(QrCode $qr, RenderSpec $spec): string
    {
        $spec->assertValid();

        $failures = $spec->contrastFailures();

        if ($failures !== []) {
            throw new InvalidArgumentException(__('qr.contrast_failed', [
                'fields' => implode(', ', array_map(
                    static fn (string $key): string => __('qr.style.'.$key),
                    $failures,
                )),
            ]));
        }

        $module = $this->module($spec->pattern);

        $style = new RendererStyle(
            size: $spec->size,
            margin: self::QUIET_ZONE_MODULES,
            module: $module,
            eye: $this->eye($spec->eye, $spec->pattern),
            fill: $this->fill($spec),
        );

        $backend = $spec->format === QrFormat::Png
            ? new ImagickImageBackEnd
            : new SvgImageBackEnd;

        $image = (new Writer(new ImageRenderer($style, $backend)))->writeString(
            $this->scanUrl($qr),
            'utf-8',
            // A logo destroys modules. H tolerates ~30% damage; M tolerates ~15%
            // and would hand out codes that scan on the design screen and fail on
            // a wall.
            $spec->hasLogo() ? ErrorCorrectionLevel::H() : ErrorCorrectionLevel::M(),
        );

        if (! $spec->hasLogo()) {
            return $image;
        }

        return $spec->format === QrFormat::Png
            ? $this->compositeLogoOnPng($image, $spec)
            : $this->compositeLogoOnSvg($image, $spec);
    }

    private function module(QrPattern $pattern): ModuleInterface
    {
        return match ($pattern) {
            QrPattern::Square => SquareModule::instance(),
            QrPattern::Rounded => new RoundnessModule(RoundnessModule::MEDIUM),
            QrPattern::RoundedStrong => new RoundnessModule(RoundnessModule::STRONG),
            QrPattern::Dots => new DotsModule(DotsModule::LARGE),
            QrPattern::DotsSmall => new DotsModule(DotsModule::SMALL),
        };
    }

    /**
     * `Auto` means "match the pattern", not "let bacon decide".
     *
     * bacon's default derives the eye from the module, and for the dot patterns
     * that draws each finder square as separated dots — which erases the solid
     * 1:1:3:1:1 ring a decoder locks onto. Measured against zxing-cpp: dots+auto
     * decoded 3 times out of 20 and small-dots+auto 0 out of 20, while the same
     * patterns with a circle eye decoded 20 out of 20. So dots resolve to a solid
     * circle, which is both scannable and the shape a designer would have picked.
     */
    private function eye(QrEye $eye, QrPattern $pattern): ?EyeInterface
    {
        return match ($eye) {
            QrEye::Auto => match ($pattern) {
                QrPattern::Dots, QrPattern::DotsSmall => SimpleCircleEye::instance(),
                // A solid module already derives a solid eye, and null is how bacon
                // is asked for that.
                QrPattern::Square, QrPattern::Rounded, QrPattern::RoundedStrong => null,
            },
            QrEye::Square => SquareEye::instance(),
            QrEye::Circle => SimpleCircleEye::instance(),
            QrEye::CircleInSquare => new CompositeEye(SquareEye::instance(), SimpleCircleEye::instance()),
        };
    }

    private function fill(RenderSpec $spec): Fill
    {
        $background = $this->rgb($spec->background);

        $eyeFill = $spec->eyeColor === null
            ? EyeFill::inherit()
            : EyeFill::uniform($this->rgb($spec->eyeColor));

        if ($spec->hasGradient()) {
            $gradient = new Gradient(
                $this->rgb($spec->foreground),
                $this->rgb((string) $spec->gradientTo),
                $this->gradientType($spec->gradientType),
            );

            return Fill::withForegroundGradient($background, $gradient, $eyeFill, $eyeFill, $eyeFill);
        }

        return Fill::withForegroundColor(
            $background,
            $this->rgb($spec->foreground),
            $eyeFill,
            $eyeFill,
            $eyeFill,
        );
    }

    private function gradientType(QrGradientType $type): GradientType
    {
        return match ($type) {
            QrGradientType::Vertical => GradientType::VERTICAL(),
            QrGradientType::Horizontal => GradientType::HORIZONTAL(),
            QrGradientType::Diagonal => GradientType::DIAGONAL(),
            QrGradientType::InverseDiagonal => GradientType::INVERSE_DIAGONAL(),
            QrGradientType::Radial => GradientType::RADIAL(),
        };
    }

    private function rgb(string $hex): Rgb
    {
        $hex = ltrim($hex, '#');

        return new Rgb(
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        );
    }

    /**
     * Read the logo's bytes, having first satisfied ourselves it is a raster image
     * of a sane size.
     *
     * `new Imagick($path)` is not a file open — it is ImageMagick's FILENAME
     * parser, which honours pseudo-format prefixes (`msl:`, `https:`, `text:`,
     * `ephemeral:`) and frame suffixes (`logo.png[0]`). Handing it a stored path
     * therefore buys SSRF and arbitrary-read surface that no amount of validation
     * at upload time can take back, because the parse happens here. Reading the
     * bytes ourselves and using `readImageBlob()` removes the parser from the
     * picture entirely.
     *
     * `getimagesize()` then does two jobs: it rejects anything that is not one of
     * three raster formats — which is what stops an MVG or SVG payload reaching a
     * delegate — and it reports the dimensions before a decode, so a 25000×25000
     * decompression bomb is refused rather than allocated.
     *
     * @return array{0: string, 1: string} raw bytes, mime type
     *
     * @throws InvalidArgumentException
     */
    private function readLogo(RenderSpec $spec): array
    {
        $path = realpath((string) $spec->logoPath);

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(__('qr.logo.unreadable'));
        }

        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2], self::ALLOWED_LOGO_TYPES, true)) {
            throw new InvalidArgumentException(__('qr.logo.unsupported'));
        }

        if ($info[0] > self::MAX_LOGO_DIMENSION || $info[1] > self::MAX_LOGO_DIMENSION) {
            throw new InvalidArgumentException(__('qr.logo.too_large'));
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new InvalidArgumentException(__('qr.logo.unreadable'));
        }

        // The mime comes from the magic-byte sniff above, never from the filename.
        return [$bytes, (string) image_type_to_mime_type($info[2])];
    }

    private function compositeLogoOnPng(string $png, RenderSpec $spec): string
    {
        [$bytes] = $this->readLogo($spec);

        $code = new Imagick;
        $code->readImageBlob($png);

        $side = (int) round($spec->size * self::LOGO_SIDE_RATIO);
        $pad = (int) round($spec->size * self::LOGO_PAD_RATIO);
        $boxSide = $side + 2 * $pad;

        $knockout = new Imagick;
        $knockout->newImage($boxSide, $boxSide, new ImagickPixel($spec->background));
        $knockout->setImageFormat('png');

        $logo = new Imagick;
        $logo->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, self::IMAGICK_MEMORY_LIMIT);
        $logo->readImageBlob($bytes);
        $logo->setImageFormat('png');
        $logo->thumbnailImage($side, $side, true);

        $knockout->compositeImage(
            $logo,
            Imagick::COMPOSITE_OVER,
            (int) (($boxSide - $logo->getImageWidth()) / 2),
            (int) (($boxSide - $logo->getImageHeight()) / 2),
        );

        $offset = (int) (($spec->size - $boxSide) / 2);
        $code->compositeImage($knockout, Imagick::COMPOSITE_OVER, $offset, $offset);

        $blob = $code->getImageBlob();

        // A queue worker renders thousands of these in one process; Imagick holds
        // its pixel cache outside PHP's memory_limit, so nothing here is reclaimed
        // by refcounting when the method returns.
        $logo->clear();
        $knockout->clear();
        $code->clear();

        return $blob;
    }

    /**
     * The SVG backend writes `viewBox="0 0 {size} {size}"`, so the code's user
     * units and the spec's pixels are the same number and the overlay needs no
     * scaling. The logo is embedded as a data URI rather than linked: an exported
     * file has to keep working on a designer's machine, off our domain.
     *
     * Both `href` and `xlink:href` are written. bacon emits an SVG 1.1 document,
     * and 1.1-only consumers — older librsvg, Inkscape before 1.0, Illustrator's
     * importer, a fair number of print RIPs — ignore the bare `href` and drop the
     * logo without an error. Since SVG is canonical precisely because a print shop
     * can use it, the failure that matters is the one nobody sees until the job
     * comes back with a blank square.
     */
    private function compositeLogoOnSvg(string $svg, RenderSpec $spec): string
    {
        [$contents, $mime] = $this->readLogo($spec);

        $side = $spec->size * self::LOGO_SIDE_RATIO;
        $pad = $spec->size * self::LOGO_PAD_RATIO;
        $boxSide = $side + 2 * $pad;
        $boxOffset = ($spec->size - $boxSide) / 2;
        $logoOffset = ($spec->size - $side) / 2;

        $uri = sprintf('data:%s;base64,%s', $mime, base64_encode($contents));

        $overlay = sprintf(
            '<rect x="%1$.3f" y="%1$.3f" width="%2$.3f" height="%2$.3f" fill="%3$s"/>'
            .'<image x="%4$.3f" y="%4$.3f" width="%5$.3f" height="%5$.3f"'
            .' href="%6$s" xlink:href="%6$s" preserveAspectRatio="xMidYMid meet"/>',
            $boxOffset,
            $boxSide,
            $spec->background,
            $logoOffset,
            $side,
            $uri,
        );

        $closing = strrpos($svg, '</svg>');

        if ($closing === false) {
            return $svg;
        }

        // `xlink:href` without its namespace declared is a malformed document that
        // strict parsers reject outright, which would be worse than the missing
        // logo it exists to prevent.
        $svg = str_replace(
            '<svg xmlns="http://www.w3.org/2000/svg"',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"',
            $svg,
        );

        $closing = (int) strrpos($svg, '</svg>');

        return substr($svg, 0, $closing).$overlay.substr($svg, $closing);
    }
}
