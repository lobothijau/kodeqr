<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Redis is as much test state as the database is: the scan buffer, the cap
    // counters and the uniqueness claims all outlive a test otherwise. Database 15
    // is set in phpunit.xml, so this cannot reach a developer's working data.
    ->beforeEach(function (): void {
        if (redisReachable()) {
            Redis::connection()->flushdb();
        }

        // Inertia pages resolve their component through the Vite manifest, so every
        // page test would otherwise depend on `npm run build` having been run — and
        // fail in CI for a reason that has nothing to do with the code under test.
        $this->withoutVite();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Answered once per run. Tests that need a real Redis skip without one; the MySQL CI
 * leg runs with a server and --fail-on-skipped, so they cannot quietly stop running.
 */
function redisReachable(): bool
{
    static $reachable = null;

    if ($reachable === null) {
        try {
            Redis::connection()->ping();
            $reachable = true;
        } catch (Throwable) {
            $reachable = false;
        }
    }

    return $reachable;
}

function skipWithoutRedis(): bool
{
    return ! redisReachable();
}

/**
 * Convert an HSL triple to a `#rrggbb` string, so a token expressed in HSL can be
 * compared against a hex literal without eyeballing.
 */
function hslToHex(float $h, float $s, float $l): string
{
    $s /= 100;
    $l /= 100;

    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;

    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0.0],
        $h < 120 => [$x, $c, 0.0],
        $h < 180 => [0.0, $c, $x],
        $h < 240 => [0.0, $x, $c],
        $h < 300 => [$x, 0.0, $c],
        default => [$c, 0.0, $x],
    };

    return sprintf(
        '#%02x%02x%02x',
        (int) round(($r + $m) * 255),
        (int) round(($g + $m) * 255),
        (int) round(($b + $m) * 255),
    );
}

/**
 * WCAG 2.x relative luminance of a `#rrggbb` colour.
 */
function relativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');

    $linear = static fn (float $channel): float => $channel <= 0.03928
        ? $channel / 12.92
        : (($channel + 0.055) / 1.055) ** 2.4;

    return 0.2126 * $linear((int) hexdec(substr($hex, 0, 2)) / 255)
        + 0.7152 * $linear((int) hexdec(substr($hex, 2, 2)) / 255)
        + 0.0722 * $linear((int) hexdec(substr($hex, 4, 2)) / 255);
}

function contrastRatio(string $foreground, string $background): float
{
    $a = relativeLuminance($foreground);
    $b = relativeLuminance($background);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/**
 * Composite a colour over a base at the given alpha, so an overlay token can be
 * measured as the colour it actually paints rather than as its own value.
 */
function compositeOver(string $foreground, float $alpha, string $background): string
{
    $fg = ltrim($foreground, '#');
    $bg = ltrim($background, '#');

    $channel = static fn (int $offset): int => (int) round(
        (int) hexdec(substr($bg, $offset, 2)) * (1 - $alpha)
        + (int) hexdec(substr($fg, $offset, 2)) * $alpha
    );

    return sprintf('#%02x%02x%02x', $channel(0), $channel(2), $channel(4));
}

/**
 * Every `--token: hsl(...)` in app.css, resolved to hex.
 *
 * @return array<string, string>
 */
function paletteTokens(): array
{
    preg_match_all(
        '/^\s*--([a-z-]+):\s*hsl\(([\d.]+)\s+([\d.]+)%\s+([\d.]+)%\)\s*;/m',
        (string) file_get_contents(resource_path('css/app.css')),
        $matches,
        PREG_SET_ORDER,
    );

    $tokens = [];

    foreach ($matches as [, $name, $h, $s, $l]) {
        $tokens[$name] = hslToHex((float) $h, (float) $s, (float) $l);
    }

    return $tokens;
}
