<?php

declare(strict_types=1);

use App\Enums\Package;
use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Http\Controllers\RedirectController;
use App\Models\QrCode;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

function qrCode(QrCodeStatus $status = QrCodeStatus::Active, string $url = 'https://example.test/menu'): QrCode
{
    return QrCode::factory()->create([
        'status' => $status,
        'destination' => ['url' => $url],
    ]);
}

/**
 * Writes a destination the M1-T2 renderer would refuse, the only way such a row can
 * actually come to exist: outside Eloquent. The redirect path has to degrade on a
 * legacy or hand-edited row, not trust that the write side always ran.
 *
 * @param  array<string, mixed>  $destination
 */
function plantDestination(QrCode $code, array $destination): QrCode
{
    DB::table('qr_codes')->where('id', $code->id)->update(['destination' => json_encode($destination)]);
    Cache::forget(RedirectController::cacheKey($code->slug));

    return $code;
}

it('redirects an active code to its destination', function () {
    $code = qrCode(url: 'https://example.test/menu-v1');

    $this->get("/x/{$code->slug}")
        ->assertRedirect('https://example.test/menu-v1')
        ->assertStatus(Response::HTTP_FOUND);

    // A phone that caches this 302 keeps using the old destination forever. Symfony
    // appends `private`; `no-store` is the load-bearing half.
    expect($this->get("/x/{$code->slug}")->headers->get('Cache-Control'))->toContain('no-store');
});

it('issues no SQL at all on a warm cache hit', function () {
    $code = qrCode();

    $this->get("/x/{$code->slug}")->assertRedirect();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get("/x/{$code->slug}")->assertRedirect();

    // Invariant I1: one Redis read and a 302. Nothing else.
    expect(DB::getQueryLog())->toBe([]);
});

it('returns the new destination immediately after an edit', function () {
    $code = qrCode(url: 'https://example.test/before');

    $this->get("/x/{$code->slug}")->assertRedirect('https://example.test/before');

    $code->update(['destination' => ['url' => 'https://example.test/after']]);

    // The entire product promise: same printed paper, new destination, no wait.
    $this->get("/x/{$code->slug}")->assertRedirect('https://example.test/after');
});

it('drops the old cache entry when a slug changes', function () {
    $code = qrCode();
    $old = $code->slug;

    $this->get("/x/{$old}")->assertRedirect();

    // Set directly, not filled: M1-T2 dropped `slug` from the fillable list so a
    // request payload cannot choose one. A rename is an internal write from here on.
    $code->slug = 'Zz9Yx8';
    $code->save();

    // A stale entry under the old key would answer for six hours.
    expect(Cache::get(RedirectController::cacheKey($old)))->toBeNull();
    $this->get("/x/{$old}")->assertNotFound();
    $this->get('/x/Zz9Yx8')->assertRedirect();
});

it('shows a branded page and no destination for every non-active status', function (QrCodeStatus $status, int $expected) {
    $code = qrCode($status, 'https://phishing.test/steal');

    $response = $this->get("/x/{$code->slug}");

    $response->assertStatus($expected)
        ->assertHeaderMissing('Location')
        ->assertDontSee('phishing.test');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'paused' => [QrCodeStatus::Paused, Response::HTTP_GONE],
    'over quota' => [QrCodeStatus::OverQuota, Response::HTTP_GONE],
    'blocked' => [QrCodeStatus::Blocked, Response::HTTP_GONE],
]);

it('tells a scanner in Bahasa what happened', function () {
    $code = qrCode(QrCodeStatus::Paused);

    // Constraint 10: nothing user-facing is hardcoded, and the scanner reads Bahasa.
    $this->get("/x/{$code->slug}")
        ->assertSee(__('redirect.inactive.title'))
        ->assertDontSee('redirect.inactive');
});

it('speaks Bahasa to a scanner by default', function () {
    // The config default alone is not enough — .env wins, and shipping APP_LOCALE=en
    // silently served every scanner English while every test still passed.
    expect(config('app.locale'))->toBe('id');

    $this->get('/x/ABC')->assertSee('QR ini tidak ditemukan');
});

it('renders scanner pages as light, iconographic, JS-free HTML', function () {
    $code = qrCode(QrCodeStatus::Paused);

    $html = $this->get("/x/{$code->slug}")->getContent();

    // Read outdoors next to printed paper: light only, an inline vector icon rather
    // than an emoji whose glyph varies per handset, and nothing to download.
    expect($html)->toContain('<svg')
        ->and($html)->toContain('content="light"')
        ->and($html)->not->toContain('prefers-color-scheme')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('fonts.bunny.net')
        ->and($html)->not->toContain('/build/');
});

it('colours each page by how bad the news is', function (QrCodeStatus $status, string $tone) {
    $code = qrCode($status);

    // A scanner reads severity before they read words: amber for something the owner
    // can undo, red for something they should walk away from.
    expect($this->get("/x/{$code->slug}")->getContent())->toContain("card tone-{$tone}");
})->with([
    'paused is recoverable' => [QrCodeStatus::Paused, 'warning'],
    'over quota is recoverable' => [QrCodeStatus::OverQuota, 'warning'],
    'blocked is not' => [QrCodeStatus::Blocked, 'danger'],
]);

it('stays a single small request', function () {
    $code = qrCode(QrCodeStatus::Paused);

    // Inline CSS, inline SVG, no script, no font fetch: one round trip on cellular.
    expect(strlen($this->get("/x/{$code->slug}")->getContent()))->toBeLessThan(8192);
});

it('keeps a single Indonesian locale', function () {
    // Indonesian-only product: no lang/en to drift out of sync with lang/id.
    expect(is_dir(base_path('lang/en')))->toBeFalse()
        ->and(config('app.locale'))->toBe('id')
        ->and(config('app.fallback_locale'))->toBe('id');
});

it('brands the page for a well-formed slug that does not exist', function () {
    $this->get('/x/Zz9Yx8')
        ->assertNotFound()
        ->assertSee(__('redirect.not_found.title'))
        // A dead code is something wrong, not a shrug.
        ->assertSee('card tone-warning', escape: false);
});

it('rejects a malformed slug at the router without touching the database', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();

    // The alphabet excludes 0 1 I L O i l o, so these can never be real codes. This
    // route gets fuzzed; garbage must cost nothing.
    foreach (['abc', 'toolongslug', 'ABC12X0', 'Ol1I23', 'abc-12', '../../etc'] as $slug) {
        $this->get("/x/{$slug}")->assertNotFound();
    }

    expect(DB::getQueryLog())->toBe([]);
});

it('answers a hundred fuzzed slugs with zero queries', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();

    for ($i = 0; $i < 100; $i++) {
        $this->get('/x/'.str_pad((string) $i, 7, '0'));
    }

    expect(DB::getQueryLog())->toBe([]);
});

it('carries the owner plan and scan cap into the cache entry', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['plan' => Plan::Business]);
    $code = QrCode::factory()->for($user)->create(['destination' => ['url' => 'https://example.test']]);

    $this->get("/x/{$code->slug}");

    // T3 and T6 read these off the warm entry, so the warm path never needs the
    // user, the subscription or config to decide anything.
    expect(Cache::get(RedirectController::cacheKey($code->slug)))
        ->toMatchArray([
            'status' => QrCodeStatus::Active->value,
            'plan' => Plan::Business->value,
            'scan_cap' => null,
            'scan_count_key' => "scans:count:{$code->id}",
        ]);
});

it('caps a free owner at the free plan scan cap', function () {
    $code = QrCode::factory()->create(['destination' => ['url' => 'https://example.test']]);

    $this->get("/x/{$code->slug}");

    expect(Cache::get(RedirectController::cacheKey($code->slug))['scan_cap'])->toBe(500);
});

it('never lets an internal failure reach the scanner', function () {
    Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('valkey is down'));

    // Invariant I2. A 500 at a printed code is indistinguishable from a dead product.
    $this->get('/x/Zz9Yx8')
        ->assertOk()
        ->assertSee(__('redirect.unavailable.title'));
});

it('shows the branded page rather than redirecting when an active code has no destination', function () {
    $code = plantDestination(QrCode::factory()->create(), ['url' => 'https://example.test']);

    $this->get("/x/{$code->slug}")
        ->assertOk()
        ->assertHeaderMissing('Location')
        ->assertSee(__('redirect.unavailable.title'));
});

it('caches a miss so a fuzzed but well-formed slug costs one query, not one per scan', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach (range(1, 20) as $ignored) {
        $this->get('/x/Zz9Yx8')->assertNotFound();
    }

    // Without the negative entry this route is unauthenticated, unbounded SQL for
    // anyone willing to walk the slug space.
    expect(DB::getQueryLog())->toHaveCount(1);
});

it('resolves a code created after its miss was cached', function () {
    $this->get('/x/RedNew')->assertNotFound();

    QrCode::factory()->create([
        'slug' => 'RedNew',
        'destination' => ['url' => 'https://example.test/fresh'],
    ]);

    // The observer clears the negative entry, so a brand new code works immediately.
    $this->get('/x/RedNew')->assertRedirect('https://example.test/fresh');
});

it('resolves the seven-character collision fallback slug', function () {
    $code = QrCode::factory()->create([
        'slug' => 'RedFa77',
        'destination' => ['url' => 'https://example.test/fallback'],
    ]);

    // M1-T2 falls back to seven characters after five collisions. A {6} route
    // constraint would 404 those codes forever, after they had been printed.
    $this->get("/x/{$code->slug}")->assertRedirect('https://example.test/fallback');
});

it('refuses to put a non-http destination in a Location header', function (string $destination) {
    $code = plantDestination(QrCode::factory()->create(), ['dest_url' => $destination]);

    $this->get("/x/{$code->slug}")
        ->assertOk()
        ->assertHeaderMissing('Location')
        ->assertSee(__('redirect.unavailable.title'))
        // Our fault is still a fault: it reads as broken, not as a neutral state.
        ->assertSee('card tone-danger', escape: false);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'file' => ['file:///etc/passwd'],
    'header injection' => ["https://example.test/\r\nSet-Cookie: a=b"],
    'schemeless' => ['example.test/menu'],
    'no host' => ['http:///etc/passwd'],
]);

it('restores a renewed owner without waiting for the cache to expire', function () {
    $user = User::factory()->create();
    $subscription = Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Plus]);
    $code = QrCode::factory()->for($user)->create(['destination' => ['url' => 'https://example.test']]);

    $this->get("/x/{$code->slug}");
    expect(Cache::get(RedirectController::cacheKey($code->slug))['plan'])->toBe(Plan::Lapsed->value);

    $subscription->extend(Package::ThreeMonths);

    // No grace period cuts both ways: a renewal must take effect in the same request,
    // not six hours later when the entry happens to expire.
    expect(Cache::get(RedirectController::cacheKey($code->slug)))->toBeNull();
    $this->get("/x/{$code->slug}");
    expect(Cache::get(RedirectController::cacheKey($code->slug))['plan'])->toBe(Plan::Plus->value);
});

it('runs the redirect route with no middleware at all', function () {
    // The group is synced onto the router when the HTTP kernel boots, so ask only
    // after a real request has gone through.
    $this->get('/x/Zz9Yx8');

    // The review focus, asserted on the route's own stack as well as on the group —
    // chaining ->middleware('web') onto the route leaves the group definition
    // untouched, and that is exactly how `web` would sneak back in. Measured: this
    // is what keeps a warm scan at one Redis round-trip instead of nine.
    expect(Route::getRoutes()->getByName('redirect.show')?->gatherMiddleware())->toBe(['redirect'])
        ->and(Route::getMiddlewareGroups()['redirect'] ?? null)->toBe([])
        ->and(app('router')->resolveMiddleware(['redirect']))->toBe([]);
});

it('sets no cookies on a scan', function () {
    $code = qrCode();

    // Nothing to consent to, nothing to store: no session means no cookie banner and
    // no per-scan write.
    expect($this->get("/x/{$code->slug}")->headers->getCookies())->toBe([]);
});
