<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Models\QrCode;
use App\Models\Subscription;
use App\Models\User;

function owner(?Plan $plan = Plan::Regular): User
{
    $user = User::factory()->create();

    if ($plan !== null) {
        $plan === Plan::Lapsed
            ? Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Regular])
            : Subscription::factory()->for($user)->create(['plan' => $plan]);
    }

    return $user;
}

it('refuses to show one owner a different owner s code', function (string $method, string $route) {
    $alice = owner();
    $bob = owner();
    $code = QrCode::factory()->for($alice)->create();

    // The IDOR test. A ULID is not a secret — it appears in URLs, logs and screen
    // shares — so guessing one must buy nothing at all.
    $this->actingAs($bob)->$method(route($route, $code))->assertForbidden();
})->with([
    'edit' => ['get', 'qr-codes.edit'],
    'update' => ['patch', 'qr-codes.update'],
    'pause' => ['post', 'qr-codes.pause'],
    'destroy' => ['delete', 'qr-codes.destroy'],
]);

it('shows an owner only their own codes', function () {
    $alice = owner();
    QrCode::factory()->for($alice)->create();
    QrCode::factory()->for(owner())->count(3)->create();

    $this->actingAs($alice)->get(route('qr-codes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('qr-codes/Index')->has('codes', 1));
});

it('lets a lapsed owner look but not touch', function () {
    $lapsed = owner(Plan::Lapsed);
    $code = QrCode::factory()->for($lapsed)->create();

    // Constraint 8: their codes keep redirecting for ever, but editing, pausing and
    // deleting are the things a lapsed account loses.
    $this->actingAs($lapsed)->get(route('qr-codes.index'))->assertOk();
    $this->actingAs($lapsed)->patch(route('qr-codes.update', $code), [
        'type' => 'url', 'url' => 'https://warung.test/baru',
    ])->assertForbidden();
    $this->actingAs($lapsed)->delete(route('qr-codes.destroy', $code))->assertForbidden();
});

it('requires a login for every builder route', function (string $method, string $route) {
    $code = QrCode::factory()->for(owner())->create();

    $this->$method(route($route, $code))->assertRedirect(route('login'));
})->with([
    'index' => ['get', 'qr-codes.index'],
    'create' => ['get', 'qr-codes.create'],
    'edit' => ['get', 'qr-codes.edit'],
    // The WRITES were missing from this list, which is the half that matters: a route
    // accidentally moved outside the auth group would have kept this test green while
    // allowing unauthenticated creates.
    'store' => ['post', 'qr-codes.store'],
    'update' => ['patch', 'qr-codes.update'],
    'pause' => ['post', 'qr-codes.pause'],
    'destroy' => ['delete', 'qr-codes.destroy'],
]);

it('does not leak a code s existence through the id in the url', function () {
    $bob = owner();

    // A ULID that was never issued and one that belongs to somebody else must be
    // indistinguishable from outside, or the 404-vs-403 split is itself an oracle.
    $missing = $this->actingAs($bob)->get(route('qr-codes.edit', '01hzzzzzzzzzzzzzzzzzzzzzzz'));
    $foreign = $this->actingAs($bob)->get(route('qr-codes.edit', QrCode::factory()->for(owner())->create()));

    expect($missing->getStatusCode())->toBe($foreign->getStatusCode());
})->todo('Route-model binding 404s a missing id while the policy 403s a foreign one; decide which answer both should give.');

it('never shows an owner a raw enum value', function () {
    $user = owner();
    $code = QrCode::factory()->for($user)->create();
    $code->status = QrCodeStatus::OverQuota;
    $code->save();

    // Constraint 10. The Vue layer has no translator, so anything it renders straight
    // off the model is English by default — and "over_quota" is the worst kind, since
    // it reads as a bug to the owner rather than as a status.
    $this->actingAs($user)->get(route('qr-codes.index'))
        ->assertInertia(fn ($page) => $page
            ->where('statusLabels.over_quota', __('qr.status_label.over_quota'))
            ->where('statusLabels.blocked', __('qr.status_label.blocked')));
});
