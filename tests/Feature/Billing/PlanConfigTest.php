<?php

declare(strict_types=1);

use App\Enums\Package;
use App\Enums\Plan;
use App\Services\PlanConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('prices every purchasable tier exactly as documentation/billing.md records', function (Plan $tier, array $expected) {
    foreach ($expected as $package => $price) {
        expect(PlanConfig::price($tier, Package::from($package)))->toBe($price);
    }
})->with([
    'regular' => [Plan::Regular, ['three_months' => 149_000, 'six_months' => 269_000, 'twelve_months' => 490_000]],
    'plus' => [Plan::Plus, ['three_months' => 449_000, 'six_months' => 799_000, 'twelve_months' => 1_490_000]],
    'business' => [Plan::Business, ['three_months' => 1_349_000, 'six_months' => 2_449_000, 'twelve_months' => 4_490_000]],
]);

it('keeps every price an integer rupiah', function () {
    // Read the raw config, not PlanConfig::prices() — that filters non-ints away, so
    // a 149_000.0 typo would vanish from a pricing page instead of failing here.
    foreach ((array) config('plans') as $features) {
        foreach ((array) ($features['prices'] ?? []) as $price) {
            expect($price)->toBeInt();
        }
    }
});

it('refuses to price a plan nobody can buy', function (Plan $plan) {
    expect(PlanConfig::prices($plan))->toBe([])
        ->and(fn () => PlanConfig::price($plan, Package::TwelveMonths))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'free' => [Plan::Free],
    'lapsed' => [Plan::Lapsed],
]);

it('sells exactly the three prepaid durations, and no monthly', function (Plan $tier) {
    expect(array_keys(PlanConfig::prices($tier)))->toBe(Package::values());
})->with([
    'regular' => [Plan::Regular],
    'plus' => [Plan::Plus],
    'business' => [Plan::Business],
]);

it('configures every key for every plan', function (Plan $plan) {
    $keys = [
        'max_codes', 'scan_cap_per_code', 'retention_days', 'can_edit', 'interstitial',
        'styling', 'vector_export', 'file_qr', 'bulk', 'api_quota', 'analytics_depth',
        'seats', 'prices',
    ];

    // A missing key denies rather than throws, so only a test catches the typo.
    expect(array_keys(PlanConfig::features($plan)))->toEqualCanonicalizing($keys);
})->with(fn () => array_map(fn (Plan $plan): array => [$plan], Plan::cases()));

it('makes longer packages cheaper per month', function (Plan $tier) {
    $prices = PlanConfig::prices($tier);

    $perMonth = fn (Package $package): float => $prices[$package->value] / $package->months();

    expect($perMonth(Package::TwelveMonths))->toBeLessThan($perMonth(Package::SixMonths))
        ->and($perMonth(Package::SixMonths))->toBeLessThan($perMonth(Package::ThreeMonths));
})->with([
    'regular' => [Plan::Regular],
    'plus' => [Plan::Plus],
    'business' => [Plan::Business],
]);

it('names no plan anywhere outside config/plans.php and the enums', function () {
    $roots = [app_path(), config_path(), base_path('routes'), base_path('database'), resource_path('js')];
    $exempt = [config_path('plans.php'), app_path('Enums')];
    $offenders = [];

    foreach ($roots as $root) {
        /** @var SplFileInfo $file */
        foreach (File::allFiles($root) as $file) {
            $path = $file->getPathname();

            if (Str::startsWith($path, $exempt) || ! in_array($file->getExtension(), ['php', 'ts', 'vue', 'js'], true)) {
                continue;
            }

            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match('/[\'"](free|lapsed|regular|plus|business)[\'"]/i', $line) === 1) {
                    $offenders[] = Str::after($path, base_path().'/').':'.($number + 1);
                }
            }
        }
    }

    // Constraint 7: plan names live in config/plans.php and the Plan enum, nowhere
    // else — including the Vue side, where a `plan === 'business'` check is likeliest.
    expect($offenders)->toBe([]);
});
