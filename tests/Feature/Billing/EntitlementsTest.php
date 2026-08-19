<?php

declare(strict_types=1);

use App\Enums\Package;
use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Models\QrCode;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

it('puts a user with no subscription row on the free entitlement set', function () {
    $user = User::factory()->create();

    $entitlements = $user->entitlements();

    expect($entitlements->plan())->toBe(Plan::Free)
        ->and($entitlements->limit('max_codes'))->toBe(3)
        ->and($entitlements->limit('scan_cap_per_code'))->toBe(500)
        ->and($entitlements->limit('retention_days'))->toBe(7)
        ->and($entitlements->can('can_edit'))->toBeTrue()
        ->and($entitlements->can('interstitial'))->toBeTrue()
        ->and($entitlements->analyticsDepth())->toBe('basic');
});

it('reports unlimited as null rather than as a number', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['plan' => Plan::Business]);

    expect($user->entitlements()->limit('scan_cap_per_code'))->toBeNull()
        ->and($user->entitlements()->limit('retention_days'))->toBeNull()
        ->and($user->entitlements()->limit('max_codes'))->toBe(500);
});

it('denies a free user a fourth code', function () {
    $user = User::factory()->create();

    QrCode::factory()->for($user)->count(3)->create();

    expect($user->entitlements()->canCreateQrCode())->toBeFalse()
        ->and(Gate::forUser($user)->allows('create-qr-code'))->toBeFalse();
});

it('allows a free user a third code', function () {
    $user = User::factory()->create();

    QrCode::factory()->for($user)->count(2)->create();

    expect(Gate::forUser($user)->allows('create-qr-code'))->toBeTrue();
});

it('frees the slot of a soft-deleted code', function () {
    $user = User::factory()->create();

    $codes = QrCode::factory()->for($user)->count(3)->create();
    $codes->first()->delete();

    expect($user->entitlements()->canCreateQrCode())->toBeTrue();
});

it('counts paused and blocked codes against the limit', function () {
    $user = User::factory()->create();

    // They still exist and still redirect, so they still occupy a slot.
    QrCode::factory()->for($user)->count(2)->create();
    QrCode::factory()->for($user)->status(QrCodeStatus::Paused)->create();

    expect($user->entitlements()->canCreateQrCode())->toBeFalse();
});

it('locks creation and editing for a lapsed account', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Plus]);

    $entitlements = $user->entitlements();

    expect($entitlements->plan())->toBe(Plan::Lapsed)
        ->and($entitlements->limit('max_codes'))->toBe(0)
        ->and($entitlements->canCreateQrCode())->toBeFalse()
        ->and(Gate::forUser($user)->allows('create-qr-code'))->toBeFalse()
        ->and($entitlements->can('can_edit'))->toBeFalse()
        ->and($entitlements->analyticsDepth())->toBeNull();
});

it('keeps showing the splash to a lapsed account', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Business]);

    // Constraint 8: the codes themselves are untouched — only the owner loses things.
    expect($user->entitlements()->can('interstitial'))->toBeTrue();
});

it('makes lapsed a distinct entitlement set rather than an alias for free', function () {
    $free = User::factory()->create();
    $lapsed = User::factory()->create();
    Subscription::factory()->for($lapsed)->lapsed()->create();

    expect($lapsed->entitlements()->can('can_edit'))
        ->not->toBe($free->entitlements()->can('can_edit'))
        ->and($lapsed->entitlements()->analyticsDepth())
        ->not->toBe($free->entitlements()->analyticsDepth())
        ->and($lapsed->entitlements()->limit('max_codes'))
        ->not->toBe($free->entitlements()->limit('max_codes'));
});

it('inherits lapsed retention from the tier that expired, not from free', function (Plan $tier, ?int $expected) {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->lapsed()->create(['plan' => $tier]);

    // M5-T3 prunes on this number: a lapsed Business customer's history must survive
    // until renewal instead of being cut back to free's 7 days.
    expect($user->entitlements()->plan())->toBe(Plan::Lapsed)
        ->and($user->entitlements()->limit('retention_days'))->toBe($expected);
})->with([
    'regular' => [Plan::Regular, 365],
    'plus' => [Plan::Plus, 365],
    'business' => [Plan::Business, null],
]);

it('never leaks the inherit sentinel out of the service', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->lapsed()->create();

    expect($user->entitlements()->limit('retention_days'))->not->toBe(PlanConfig::INHERIT);
});

it('falls back to free and logs when a plan has no configuration', function () {
    Log::spy();

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['plan' => Plan::Business]);

    config()->set('plans.'.Plan::Business->value, null);

    // Must degrade, never throw: owner surfaces sit directly on top of this.
    expect($user->entitlements()->limit('max_codes'))->toBe(3)
        ->and($user->entitlements()->can('can_edit'))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'falling back to free')
            && ($context['plan'] ?? null) === Plan::Business->value);
});

it('denies an unknown feature key instead of granting it', function () {
    Log::spy();

    $user = User::factory()->create();

    expect($user->entitlements()->can('teleportation'))->toBeFalse()
        ->and($user->entitlements()->limit('teleportation'))->toBe(0);

    Log::shouldHaveReceived('warning')->twice();
});

it('stops granting paid features the moment a package expires mid-request', function () {
    Carbon::setTestNow('2026-08-19 09:00:00');

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create([
        'plan' => Plan::Plus,
        'ends_at' => Carbon::parse('2026-08-19 09:00:30'),
    ]);

    $entitlements = $user->entitlements();

    expect($entitlements->plan())->toBe(Plan::Plus)
        ->and($entitlements->can('can_edit'))->toBeTrue();

    Carbon::setTestNow('2026-08-19 09:01:00');

    // No grace period means no grace period: the same instance must flip immediately.
    expect($entitlements->plan())->toBe(Plan::Lapsed)
        ->and($entitlements->can('can_edit'))->toBeFalse()
        ->and($entitlements->canCreateQrCode())->toBeFalse();
});

it('restores everything the instant a renewal lands', function () {
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Plus]);

    expect($user->entitlements()->plan())->toBe(Plan::Lapsed)
        ->and($user->entitlements()->canCreateQrCode())->toBeFalse();

    $subscription->extend(Package::ThreeMonths);
    $user->refresh();

    // M3-T2 renews inside the webhook request and answers the customer in it.
    expect($user->entitlements()->plan())->toBe(Plan::Plus)
        ->and($user->entitlements()->can('can_edit'))->toBeTrue()
        ->and($user->entitlements()->canCreateQrCode())->toBeTrue();
});

it('preserves history rather than pruning it when inheritance cannot resolve', function () {
    Log::spy();

    $user = User::factory()->create();
    Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Business]);

    config()->set('plans.'.Plan::Business->value, null);

    // Falling back to free's 7 days here would let M5-T3 delete paid history that a
    // renewal is supposed to restore. Null means keep.
    expect($user->entitlements()->limit('retention_days'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'preserving'));
});
