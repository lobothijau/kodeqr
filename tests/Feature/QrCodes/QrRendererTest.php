<?php

declare(strict_types=1);

use App\Enums\QrEye;
use App\Enums\QrFormat;
use App\Enums\QrGradientType;
use App\Enums\QrPattern;
use App\Models\QrCode;
use App\Services\QrRenderer;
use App\Services\RenderSpec;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Module\SquareModule;
use BaconQrCode\Renderer\RendererStyle\EyeFill;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Encode a string through the same pipeline the renderer uses, so a comparison
 * isolates the payload and nothing else. This is how the "what does the image
 * actually contain" tests get certainty without shipping a QR decoder.
 */
function encodeControl(string $content, ?ErrorCorrectionLevel $ecc = null): string
{
    $style = new RendererStyle(
        size: RenderSpec::DEFAULT_SIZE,
        margin: 4,
        module: SquareModule::instance(),
        eye: null,
        fill: Fill::withForegroundColor(
            new Rgb(255, 255, 255),
            new Rgb(24, 24, 27),
            EyeFill::inherit(),
            EyeFill::inherit(),
            EyeFill::inherit(),
        ),
    );

    return (new Writer(new ImageRenderer($style, new SvgImageBackEnd)))
        ->writeString($content, 'utf-8', $ecc ?? ErrorCorrectionLevel::M());
}

/**
 * A real PNG on disk, written once per run. The renderer opens the path with
 * Imagick, so a fake string would exercise none of the composite path.
 */
function logoFixture(): string
{
    // Written per call, not cached in temp: a fixture kept across runs means a
    // changed definition silently exercises the old file, and two parallel Pest
    // workers can race one another reading a half-written PNG.
    $path = tempnam(sys_get_temp_dir(), 'kodeqr-logo').'.png';

    $logo = new Imagick;
    $logo->newImage(256, 256, new ImagickPixel('#ea580c'));
    $logo->setImageFormat('png');
    $logo->writeImage($path);
    $logo->clear();

    return $path;
}

/*
 * Constraint 4. If a code ever encodes its destination, editing that destination
 * stops changing where printed paper goes and the entire product is silently dead
 * while every test about redirects still passes. Byte equality against a control
 * encoding of the /x/ URL is the strongest statement available without a decoder;
 * the inequality half is what fails if someone "helpfully" passes dest_url in.
 */
it('encodes the scan url and never the destination', function (): void {
    $qr = QrCode::factory()->create([
        'destination' => ['url' => 'https://toko-saya.test/promo', 'dest_url' => 'https://toko-saya.test/promo'],
    ]);

    $renderer = app(QrRenderer::class);
    $svg = $renderer->render($qr, new RenderSpec);

    expect($renderer->scanUrl($qr))->toBe(rtrim((string) config('app.url'), '/').'/x/'.$qr->slug)
        ->and($svg)->toBe(encodeControl($renderer->scanUrl($qr)))
        ->and($svg)->not->toBe(encodeControl('https://toko-saya.test/promo'));
});

it('forces error correction H when a logo is present and M when it is not', function (): void {
    $qr = QrCode::factory()->create();
    $renderer = app(QrRenderer::class);
    $url = $renderer->scanUrl($qr);

    $plain = $renderer->render($qr, new RenderSpec);
    expect($plain)->toBe(encodeControl($url, ErrorCorrectionLevel::M()));

    $withLogo = $renderer->render($qr, new RenderSpec(logoPath: logoFixture()));

    // The logo path declares the xlink namespace on the root and appends the
    // overlay immediately before </svg>. Everything between those two edits must
    // be the untouched H-level render — which is what pins the ECC choice.
    $controlH = str_replace(
        '<svg xmlns="http://www.w3.org/2000/svg"',
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"',
        encodeControl($url, ErrorCorrectionLevel::H()),
    );

    expect($withLogo)->toStartWith(substr($controlH, 0, (int) strrpos($controlH, '</svg>')))
        ->and($withLogo)->toContain('<image')
        // And emphatically NOT the M-level render the no-logo branch produces.
        ->and($withLogo)->not->toContain(substr($plain, 0, (int) strrpos($plain, '</svg>')));
});

it('rejects colours a phone camera cannot separate', function (string $foreground, string $background): void {
    $qr = QrCode::factory()->create();

    expect(fn () => app(QrRenderer::class)->render(
        $qr,
        new RenderSpec(foreground: $foreground, background: $background),
    ))->toThrow(InvalidArgumentException::class, __('qr.contrast_failed', ['fields' => __('qr.style.foreground')]));
})->with([
    'white on white' => ['#ffffff', '#ffffff'],
    'light grey on white' => ['#cccccc', '#ffffff'],
    'near-black on black' => ['#111111', '#000000'],
]);

it('accepts brand colours that clear the threshold', function (string $foreground): void {
    $qr = QrCode::factory()->create();

    $svg = app(QrRenderer::class)->render($qr, new RenderSpec(foreground: $foreground));

    expect($svg)->toContain('<svg');
})->with(['#18181b', '#1d4ed8', '#0f766e', '#b91c1c']);

it('reports every offending swatch at once rather than the first', function (): void {
    $spec = new RenderSpec(
        foreground: '#eeeeee',
        background: '#ffffff',
        gradientTo: '#f5f5f5',
        eyeColor: '#fafafa',
    );

    expect($spec->contrastFailures())->toBe(['foreground', 'gradient_to', 'eye_color']);
});

it('renders svg as vector paths, not an embedded raster', function (): void {
    $qr = QrCode::factory()->create();

    $svg = app(QrRenderer::class)->render($qr, new RenderSpec(format: QrFormat::Svg));

    expect($svg)->toContain('<path')
        ->and($svg)->not->toContain('<image')
        ->and($svg)->toContain('viewBox="0 0 512 512"');
});

it('renders png bytes through imagick', function (): void {
    $qr = QrCode::factory()->create();

    $png = app(QrRenderer::class)->render($qr, new RenderSpec(format: QrFormat::Png, size: 240));

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $image = new Imagick;
    $image->readImageBlob($png);
    expect($image->getImageWidth())->toBe(240);
});

it('embeds the logo as a data uri so an exported file works off our domain', function (): void {
    $qr = QrCode::factory()->create();

    $svg = app(QrRenderer::class)->render($qr, new RenderSpec(logoPath: logoFixture()));

    // Anything fetched over the network would render as a broken box on a machine
    // that cannot reach us — which is every machine the file is emailed to.
    expect($svg)->toContain('href="data:image/png;base64,')
        ->and($svg)->not->toContain('href="http');
});

it('draws every pattern and eye combination without error', function (): void {
    $qr = QrCode::factory()->create();
    $renderer = app(QrRenderer::class);

    $drawn = [];

    foreach (QrPattern::cases() as $pattern) {
        foreach (QrEye::cases() as $eye) {
            $svg = $renderer->render($qr, new RenderSpec(pattern: $pattern, eye: $eye));

            if (str_contains($svg, '<path')) {
                $drawn[] = "{$pattern->value}+{$eye->value}";
            }
        }
    }

    expect($drawn)->toHaveCount(count(QrPattern::cases()) * count(QrEye::cases()));
});

/*
 * `toContain('Gradient')` matched linearGradient and radialGradient alike, so this
 * passed even if gradientType() mapped every case to VERTICAL — and swapping
 * Diagonal with InverseDiagonal is exactly the mistake a five-arm match invites.
 * Distinct output per case is the assertion that can actually fail.
 */
it('maps each gradient direction to a distinct rendering', function (): void {
    $qr = QrCode::factory()->create();
    $renderer = app(QrRenderer::class);

    $renders = [];

    foreach (QrGradientType::cases() as $type) {
        $renders[$type->value] = $renderer->render($qr, new RenderSpec(
            foreground: '#7c3aed',
            gradientTo: '#db2777',
            gradientType: $type,
        ));

        expect($renders[$type->value])->toContain('Gradient');
    }

    expect(array_unique($renders))->toHaveCount(count(QrGradientType::cases()));
});

it('clamps size to what a printer and a scanner can both use', function (): void {
    expect(RenderSpec::clampSize(10))->toBe(RenderSpec::MIN_SIZE)
        ->and(RenderSpec::clampSize(99999))->toBe(RenderSpec::MAX_SIZE)
        ->and(RenderSpec::clampSize(700))->toBe(700);
});

/*
 * A style column written by an older release must never turn an existing code into
 * an error page — the owner may have printed it.
 */
it('falls back to defaults on unreadable stored style', function (): void {
    $spec = RenderSpec::fromStyle([
        'pattern' => 'blob',
        'eye' => 42,
        'foreground' => 'rebeccapurple',
        'background' => '#GGGGGG',
        'gradient_type' => 'spiral',
        'size' => 'besar',
    ]);

    expect($spec->pattern)->toBe(QrPattern::Square)
        ->and($spec->eye)->toBe(QrEye::Auto)
        ->and($spec->foreground)->toBe('#18181b')
        ->and($spec->background)->toBe('#ffffff')
        ->and($spec->gradientType)->toBe(QrGradientType::Diagonal)
        ->and($spec->size)->toBe(RenderSpec::DEFAULT_SIZE)
        ->and($spec->contrastFailures())->toBe([]);
});

it('reads a well-formed stored style', function (): void {
    $spec = RenderSpec::fromStyle([
        'pattern' => 'dots',
        'eye' => 'circle_in_square',
        'foreground' => '#0F766E',
        'background' => '#ffffff',
        'gradient_to' => '#1d4ed8',
        'gradient_type' => 'radial',
        'eye_color' => '#ea580c',
    ], QrFormat::Png, 1024);

    expect($spec->pattern)->toBe(QrPattern::Dots)
        ->and($spec->eye)->toBe(QrEye::CircleInSquare)
        ->and($spec->foreground)->toBe('#0f766e')
        ->and($spec->hasGradient())->toBeTrue()
        ->and($spec->gradientType)->toBe(QrGradientType::Radial)
        ->and($spec->eyeColor)->toBe('#ea580c')
        ->and($spec->format)->toBe(QrFormat::Png)
        ->and($spec->size)->toBe(1024);
});

/*
 * Measured, not assumed. Against zxing-cpp — the decoder behind Android and Google
 * Lens — bacon's module-derived eye on the dot patterns decoded 3/20 and 0/20,
 * because separated dots destroy the solid 1:1:3:1:1 ring a camera locks onto.
 * Resolving Auto to a circle there scored 20/20. Byte equality with the explicit
 * circle render is what stops that resolution being "simplified" back to null.
 */
it('resolves auto to a solid circle eye on dot patterns', function (QrPattern $pattern): void {
    $qr = QrCode::factory()->create();
    $renderer = app(QrRenderer::class);

    expect($renderer->render($qr, new RenderSpec(pattern: $pattern, eye: QrEye::Auto)))
        ->toBe($renderer->render($qr, new RenderSpec(pattern: $pattern, eye: QrEye::Circle)));
})->with([QrPattern::Dots, QrPattern::DotsSmall]);

it('leaves auto deriving from the module on solid patterns', function (QrPattern $pattern): void {
    $qr = QrCode::factory()->create();
    $renderer = app(QrRenderer::class);

    expect($renderer->render($qr, new RenderSpec(pattern: $pattern, eye: QrEye::Auto)))
        ->not->toBe($renderer->render($qr, new RenderSpec(pattern: $pattern, eye: QrEye::Circle)));
})->with([QrPattern::Rounded, QrPattern::RoundedStrong]);

/*
 * bacon ships a PointyEye. It decoded 0/20 on every pattern at every size, so it is
 * not offered. This asserts the enum, because the cost of someone adding it back is
 * codes that look fine on screen and cannot be scanned off a wall.
 */
it('does not offer an eye shape that cannot be decoded', function (): void {
    expect(QrEye::values())->not->toContain('pointy');
});

/*
 * The encoded host must be a property of the installation, not of whoever asked.
 * `route()` roots an absolute URL at the CURRENT REQUEST, so rendering behind a
 * staging hostname, a *.laravel.cloud origin URL, or a spoofed Host header used to
 * bake that host into the image — permanently, because this ends up on paper. The
 * dual review caught this; nothing here did, so it gets a test.
 */
it('ignores the request host when building the encoded url', function (): void {
    config()->set('app.url', 'https://kodeqr.com');

    $qr = QrCode::factory()->create();
    $renderer = app(QrRenderer::class);

    $hostile = Request::create('https://evil.test/kode/'.$qr->id.'/export', 'GET');
    app()->instance('request', $hostile);
    app('url')->setRequest($hostile);

    expect($renderer->scanUrl($qr))->toBe('https://kodeqr.com/x/'.$qr->slug)
        ->and($renderer->render($qr, new RenderSpec))->toBe(
            encodeControl('https://kodeqr.com/x/'.$qr->slug)
        );
});

/*
 * The string is hand-built, so nothing but a test keeps it in step with the route
 * it has to resolve to. If someone moves /x/ this fails rather than shipping codes
 * that 404 off printed paper.
 */
it('builds the same url the redirect route serves', function (): void {
    config()->set('app.url', 'https://kodeqr.com');
    URL::forceRootUrl('https://kodeqr.com');
    URL::forceScheme('https');

    $qr = QrCode::factory()->create();

    expect(app(QrRenderer::class)->scanUrl($qr))->toBe(route('redirect.show', $qr->slug));
});

it('refuses a logo that is not a raster image, in both formats', function (QrFormat $format): void {
    $qr = QrCode::factory()->create();

    // A file ImageMagick would happily hand to a delegate, named like a PNG.
    $path = tempnam(sys_get_temp_dir(), 'kodeqr-fake').'.png';
    file_put_contents($path, "push graphic-context\nrectangle 1,1 10,10\npop graphic-context");

    expect(fn () => app(QrRenderer::class)->render($qr, new RenderSpec(format: $format, logoPath: $path)))
        ->toThrow(InvalidArgumentException::class);
})->with([QrFormat::Png, QrFormat::Svg]);

/*
 * The two formats used to disagree three different ways on the same broken state:
 * PNG threw a raw ImagickException (an uncaught 500), SVG silently returned a
 * logo-free code at ECC H, and a non-image file was base64'd into the SVG as
 * `data:text/plain`. One typed refusal for all of it.
 */
it('refuses a missing logo in both formats rather than silently dropping it', function (QrFormat $format): void {
    $qr = QrCode::factory()->create();
    $missing = sys_get_temp_dir().'/kodeqr-absent-'.bin2hex(random_bytes(6)).'.png';

    expect(fn () => app(QrRenderer::class)->render($qr, new RenderSpec(format: $format, logoPath: $missing)))
        ->toThrow(InvalidArgumentException::class);
})->with([QrFormat::Png, QrFormat::Svg]);

it('declares the xlink namespace it uses for the logo', function (): void {
    $qr = QrCode::factory()->create();

    $svg = app(QrRenderer::class)->render($qr, new RenderSpec(logoPath: logoFixture()));

    // SVG 1.1 consumers — older librsvg, Inkscape < 1.0, print RIPs — read only
    // xlink:href, and an undeclared namespace makes the document malformed.
    expect($svg)->toContain('xmlns:xlink="http://www.w3.org/1999/xlink"')
        ->and($svg)->toContain('xlink:href="data:image/png;base64,');
});

it('keeps the ISO four-module quiet zone', function (): void {
    $qr = QrCode::factory()->create();

    $svg = app(QrRenderer::class)->render($qr, new RenderSpec);

    // On a coloured background the drawn margin is the only quiet zone there is.
    expect($svg)->toContain('<g transform="translate(4,4)">');
});

it('rejects a malformed hex in any colour field, not just the required two', function (string $field): void {
    $spec = new RenderSpec(...[$field => 'rebeccapurple']);

    expect(fn () => $spec->assertValid())->toThrow(InvalidArgumentException::class);
})->with(['foreground', 'background', 'gradientTo', 'eyeColor']);

/*
 * Relative luminance is convex in sRGB, so a gradient can pass at both ends and dip
 * below the threshold in between. Codex found the case; measured, it still decodes
 * 20/20, so this is the guard meaning what it says rather than a scanning fix.
 */
it('checks a gradient along its length, not only at its stops', function (): void {
    $spec = new RenderSpec(
        foreground: '#bcad2d',
        background: '#000000',
        gradientTo: '#7cb3e2',
    );

    expect($spec->contrastFailures())->toContain('gradient_to');
});

it('accepts a numeric string size rather than reverting to the default', function (): void {
    expect(RenderSpec::fromStyle(['size' => '1024'])->size)->toBe(1024);
});
