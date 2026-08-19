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
        ->and($composer['scripts']['lint:check'])->toContain('pint --parallel --test')
        ->and($composer['scripts']['types:check'])->toContain('phpstan analyse');
});
