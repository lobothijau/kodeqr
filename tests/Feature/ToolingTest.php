<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * The build is infrastructure, and infrastructure rots silently. These assert the
 * shape of the toolchain the definition of done depends on, so a well-meaning edit
 * that quietly weakens it fails here instead of in a review nobody runs.
 */
function ciWorkflow(): array
{
    /** @var array<string, mixed> $parsed */
    $parsed = Yaml::parseFile(base_path('.github/workflows/tests.yml'));

    return $parsed;
}

it('analyses without a baseline and without silenced errors', function () {
    $config = file_get_contents(base_path('phpstan.neon')) ?: '';

    // A baseline buries today's real errors as tomorrow's accepted noise — and so
    // does ignoreErrors, which is the same hole under a different name.
    expect($config)->not->toContain('baseline')
        ->and($config)->not->toContain('ignoreErrors')
        ->and($config)->not->toContain('reportUnmatchedIgnoredErrors: false')
        ->and(glob(base_path('phpstan*baseline*')))->toBe([]);
});

it('analyses at level 6 or stricter', function () {
    preg_match('/^\s*level:\s*(\d+|max)/m', file_get_contents(base_path('phpstan.neon')) ?: '', $matches);
    $level = $matches[1] ?? '0';

    expect($level === 'max' ? PHP_INT_MAX : (int) $level)->toBeGreaterThanOrEqual(6);
});

it('connects to the engine the environment asked for', function () {
    // The load-bearing one: this fails on the MySQL leg the moment something — a
    // forced phpunit.xml env, a dropped job variable — quietly routes the run back
    // to SQLite, which is how a second leg turns into a second SQLite leg.
    expect(DB::connection()->getDriverName())->toBe(env('DB_CONNECTION', 'sqlite'));
});

it('runs the suite against MySQL as well as SQLite', function () {
    /** @var array<string, mixed> $job */
    $job = ciWorkflow()['jobs']['mysql'];
    $commands = collect($job['steps'])->pluck('run')->filter()->implode("\n");

    // MySQL-only tests skip themselves on SQLite; without this leg they would never
    // run anywhere, and production is MySQL. --fail-on-skipped is what makes a test
    // that quietly stops running a build failure instead of a silence.
    expect($job['services']['mysql']['image'])->toStartWith('mysql:8')
        ->and($job['env']['DB_CONNECTION'])->toBe('mysql')
        ->and($job)->not->toHaveKey('if')
        ->and($commands)->toContain('php artisan test --compact --fail-on-skipped');
});

it('runs CI on pushes to main, not only on pull requests', function () {
    // kodeqr commits straight to main; a PR-only trigger would never fire, and a
    // push trigger on some other branch is the same silence.
    expect(ciWorkflow()['on']['push']['branches'])->toContain('main')
        ->and(ciWorkflow()['on'])->toHaveKey('pull_request');
});

it('gives every commit to main its own verdict', function () {
    // Cancelling superseded runs is right for PRs and wrong for main: back-to-back
    // commits would leave the earlier one with no result at all.
    expect(ciWorkflow()['concurrency']['cancel-in-progress'])
        ->toBe("\${{ github.event_name == 'pull_request' }}");
});

it('defaults a deployed environment to the cache store the redirect path needs', function () {
    $example = file_get_contents(base_path('.env.example')) ?: '';

    // The `database` store answers /x/{slug} with SQL on every scan and no test can
    // see it — phpunit forces the array store.
    expect($example)->toContain('CACHE_STORE=redis')
        ->and($example)->not->toContain("\nCACHE_STORE=database");
});

it('ships an executable pre-push hook that runs the same checks as CI', function () {
    $hook = base_path('.githooks/pre-push');

    $commands = array_filter(
        array_map(trim(...), file($hook) ?: []),
        fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    );

    // Commented-out is not installed: assert the command actually executes.
    expect(is_file($hook))->toBeTrue()
        ->and(is_executable($hook))->toBeTrue()
        ->and($commands)->toContain('composer test');
});

it('installs the hook path as part of setup, without breaking a gitless checkout', function () {
    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '{}', true);

    // A Docker build that excludes .git must not die on the last step of setup.
    expect($composer['scripts']['setup'])->toContain('@hooks:install')
        ->and($composer['scripts']['hooks:install'][0])->toContain("file_exists('.git')")
        ->and($composer['scripts']['hooks:install'][0])->toContain('core.hooksPath .githooks');
});

it('keeps pint, larastan and pest in the one script CI and the hook both call', function () {
    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '{}', true);

    expect($composer['scripts']['test'])->toContain('@lint:check')
        ->and($composer['scripts']['test'])->toContain('@types:check')
        ->and(implode(' ', $composer['scripts']['lint:check']))->toContain('pint --parallel --test')
        // The memory limit is load-bearing: on a cold cache (i.e. every CI run) the
        // default 128M crashes a parallel worker mid-analysis.
        ->and(implode(' ', $composer['scripts']['types:check']))->toContain('phpstan analyse --memory-limit');
});

/*
 * The app has ONE palette. `dark:` is a BUILT-IN Tailwind v4 variant whose fallback
 * is `@media (prefers-color-scheme: dark)`, so a `dark:` utility copied back into
 * this codebase would not sit there inert — it would fire on any dark-mode phone,
 * invisible to anyone developing in light mode.
 *
 * Two halves, and both matter: no `dark:` in our own source, and the variant still
 * overridden to a selector nothing carries so the ones we do NOT control stay dead.
 */
it('keeps exactly one palette', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path(), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['vue', 'ts', 'js', 'css', 'php'], true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/(?<![\w-])dark:[a-z0-9[]/i', $contents) === 1) {
            $offenders[] = str_replace(resource_path().'/', '', $file->getPathname());
        }
    }

    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($offenders)->toBe([])
        // Neutralised, NOT deleted. Deleting the override hands `dark:` back to its
        // built-in @media fallback, and utilities get compiled from sources this
        // project does not own — Laravel's pagination views, the compiled Blade
        // cache, even class names quoted in documentation markdown.
        ->and($css)->toContain('@custom-variant dark (&:where(.no-dark-mode *));')
        ->and($css)->not->toContain('.dark {');
});

/*
 * The canvas colour is hardcoded in FOUR places outside app.css, because each one
 * has to work before or without the stylesheet: the root document paints it inline
 * so there is no flash on load and no mismatched band on overscroll, advertises it
 * as theme-color so mobile browser chrome matches, and the two scanner-facing Blade
 * pages carry their own copy because they have no build step at all.
 *
 * Change --background and miss one, and the suite stays green while the page
 * constraint 8 promises is always branded sits on the old colour.
 */
it('paints one canvas everywhere it is hardcoded', function () {
    preg_match(
        '/--background:\s*hsl\(([^)]+)\)/',
        (string) file_get_contents(resource_path('css/app.css')),
        $token,
    );

    expect($token[1] ?? null)->not->toBeNull();

    [$h, $s, $l] = array_map(
        static fn (string $part): float => (float) rtrim($part, '%'),
        preg_split('/\s+/', trim($token[1])) ?: [],
    );

    $canvas = hslToHex($h, $s, $l);

    $sites = [
        'views/app.blade.php',
        'views/redirect/layout.blade.php',
        'views/abuse/report.blade.php',
    ];

    // Collected, not asserted one by one: Pest's toContain takes further NEEDLES,
    // not a failure message, so a message argument silently becomes a second
    // assertion that always fails.
    $missing = array_values(array_filter(
        $sites,
        static fn (string $site): bool => ! str_contains(
            strtolower((string) file_get_contents(resource_path($site))),
            $canvas,
        ),
    ));

    expect($missing)->toBe([]);

    // Both metas and the inline paint, specifically — not merely the string
    // appearing somewhere in the file.
    $root = strtolower((string) file_get_contents(resource_path('views/app.blade.php')));

    expect($root)->toContain("background-color: {$canvas};")
        ->and($root)->toContain("<meta name=\"theme-color\" content=\"{$canvas}\">");
});

/*
 * The canvas is not the only colour duplicated into the build-step-free Blade pages:
 * --brand is too. The failure mode is the next palette change, where --primary moves
 * in app.css, the suite stays green, and the page constraint 8 promises a scanner
 * always sees keeps a stale brand — on printed paper somebody is standing in front of.
 */
it('carries one brand colour into the pages that have no stylesheet', function () {
    preg_match(
        '/--primary:\s*hsl\(([^)]+)\)/',
        (string) file_get_contents(resource_path('css/app.css')),
        $token,
    );

    expect($token[1] ?? null)->not->toBeNull();

    [$h, $s, $l] = array_map(
        static fn (string $part): float => (float) rtrim($part, '%'),
        preg_split('/\s+/', trim($token[1])) ?: [],
    );

    $brand = hslToHex($h, $s, $l);

    $missing = array_values(array_filter(
        ['views/redirect/layout.blade.php', 'views/abuse/report.blade.php'],
        static fn (string $site): bool => ! str_contains(
            strtolower((string) file_get_contents(resource_path($site))),
            "--brand: {$brand}",
        ),
    ));

    expect($missing)->toBe([]);
});
