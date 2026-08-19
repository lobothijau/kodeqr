<?php

declare(strict_types=1);

use App\Enums\Package;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('puts a user with no subscription row on the free plan', function () {
    $user = User::factory()->create();

    expect($user->subscription)->toBeNull()
        ->and($user->currentPlan())->toBe(Plan::Free);
});

it('refuses to store free or lapsed as a plan value', function (string $plan) {
    $user = User::factory()->create();

    // Both states are answered by the presence and dates of the row itself.
    expect(fn () => DB::table('subscriptions')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $user->id,
        'plan' => $plan,
        'package' => Package::ThreeMonths->value,
        'starts_at' => now(),
        'ends_at' => now()->addMonths(3),
        'status' => SubscriptionStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with(['free', 'lapsed']);

it('allows only one subscription row per user', function () {
    $user = User::factory()->create();

    Subscription::factory()->for($user)->create();

    expect(fn () => Subscription::factory()->for($user)->create())
        ->toThrow(QueryException::class);
});

it('stacks an extension onto an unexpired package', function () {
    Carbon::setTestNow('2026-08-19 09:00:00');

    $subscription = Subscription::factory()->create([
        'ends_at' => Carbon::parse('2026-11-19 09:00:00'),
    ]);

    // Buying a second 3-month package must land six months out, not three.
    $subscription->extend(Package::ThreeMonths);

    expect($subscription->ends_at->toDateTimeString())->toBe('2027-02-19 09:00:00');
});

it('extends a lapsed package from now, not from its stale end date', function () {
    Carbon::setTestNow('2026-08-19 09:00:00');

    $subscription = Subscription::factory()->lapsed()->create();

    $subscription->extend(Package::SixMonths);

    expect($subscription->ends_at->toDateTimeString())->toBe('2027-02-19 09:00:00');
});

it('reactivates when extended', function () {
    $subscription = Subscription::factory()->lapsed()->create();

    $subscription->extend(Package::TwelveMonths);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('keeps the paid plan until the exact moment of expiry', function () {
    Carbon::setTestNow('2026-08-19 09:00:00');

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create([
        'plan' => Plan::Plus,
        'ends_at' => Carbon::parse('2026-08-19 09:00:01'),
    ]);

    expect($user->currentPlan())->toBe(Plan::Plus);

    // One second later the package is over. No grace, no buffer.
    Carbon::setTestNow('2026-08-19 09:00:02');

    expect($user->fresh()->currentPlan())->toBe(Plan::Lapsed);
});

it('reports lapsed once expired even when the status column still reads active', function () {
    $user = User::factory()->create();

    // The nightly sweep has not run yet, so the column is stale. Entitlements derive
    // from dates precisely so this window cannot hand out paid features.
    Subscription::factory()->for($user)->create([
        'plan' => Plan::Business,
        'ends_at' => now()->subDay(),
        'status' => SubscriptionStatus::Active,
    ]);

    expect($user->currentPlan())->toBe(Plan::Lapsed);
});

it('maps each package to its month count', function () {
    expect(Package::ThreeMonths->months())->toBe(3)
        ->and(Package::SixMonths->months())->toBe(6)
        ->and(Package::TwelveMonths->months())->toBe(12);
});

it('stores ends_at in UTC and casts it back to a Carbon instance', function () {
    $subscription = Subscription::factory()->create([
        'ends_at' => Carbon::parse('2026-11-19 09:00:00', 'UTC'),
    ]);

    expect($subscription->fresh()->ends_at)->toBeInstanceOf(CarbonInterface::class)
        ->and(DB::table('subscriptions')->value('ends_at'))->toStartWith('2026-11-19 09:00:00');
});
