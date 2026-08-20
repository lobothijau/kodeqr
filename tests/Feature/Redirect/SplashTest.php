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
use Illuminate\Support\Facades\Redis;

function splashCode(?Plan $plan = null, string $url = 'https://menu.warungmakan.test/daftar-menu'): QrCode
{
    $user = User::factory()->create();

    if ($plan !== null) {
        // Lapsed is never a stored plan — the DB enum refuses it. It is a paid row
        // whose ends_at has passed, which is what the factory state produces.
        $plan === Plan::Lapsed
            ? Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Regular])
            : Subscription::factory()->for($user)->create(['plan' => $plan]);
    }

    return QrCode::factory()->for($user)->create(['destination' => ['url' => $url]]);
}

it('shows a free-plan scanner where they are about to be sent', function () {
    $code = splashCode();

    $response = $this->get("/x/{$code->slug}");

    // The host is the whole point: it is the last moment somebody standing in front
    // of printed paper can decline.
    $response->assertOk()
        ->assertHeaderMissing('Location')
        ->assertSee(__('redirect.splash.title'))
        ->assertSee('menu.warungmakan.test')
        ->assertSee(__('redirect.splash.action'))
        ->assertSee('Buat QR gratis', escape: false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('points the refresh straight at the destination, never back through the route', function () {
    $code = splashCode();

    $html = $this->get("/x/{$code->slug}")->getContent();

    // The double-count trap: a refresh aimed at /x/{slug} would record every
    // free-tier scan twice, and the second one would look like a real second visit.
    expect($html)->toContain('http-equiv="refresh"')
        ->toContain('url=https://menu.warungmakan.test/daftar-menu')
        ->and($html)->not->toContain("url=/x/{$code->slug}");
});

it('sends a paid scanner straight through with no splash', function (Plan $plan) {
    $code = splashCode($plan);

    $this->get("/x/{$code->slug}")
        ->assertRedirect('https://menu.warungmakan.test/daftar-menu')
        ->assertDontSee(__('redirect.splash.title'));
})->with([
    'regular' => [Plan::Regular],
    'plus' => [Plan::Plus],
    'business' => [Plan::Business],
]);

it('records exactly one scan for a splash, the same as for a redirect', function () {
    $code = splashCode();

    $this->get("/x/{$code->slug}")->assertOk();

    // The splash IS the scan. It is pushed once, server-side, at render.
    expect((int) Redis::connection()->llen(RedirectController::BUFFER_KEY))->toBe(1);
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('still reaches the destination for a lapsed owner, forever', function () {
    $code = splashCode(Plan::Lapsed);

    $this->get("/x/{$code->slug}")
        ->assertOk()
        ->assertSee('menu.warungmakan.test');
});

it('tells a lapsed owner nothing about money, because their customers read it', function () {
    $code = splashCode(Plan::Lapsed);

    $response = $this->get("/x/{$code->slug}");

    // "Paket Anda telah berakhir" addressed to a stranger is nonsense to them and
    // tells a business's customers that the business did not pay. The owner gets a
    // way back in; nobody else reads anything into it.
    $response->assertSee('Dikelola dengan', escape: false)
        ->assertDontSee('berakhir')
        ->assertDontSee('perpanjang')
        ->assertDontSee('Paket');
});

it('records no scan at all for a lapsed owner', function () {
    $code = splashCode(Plan::Lapsed);

    $this->get("/x/{$code->slug}")->assertOk();

    // Not the event, not the counter: a lapsed code redirects forever for nothing,
    // so serving one has to stay close to free (documentation/billing.md).
    expect((int) Redis::connection()->llen(RedirectController::BUFFER_KEY))->toBe(0)
        ->and(Redis::connection()->get("scans:count:{$code->id}"))->toBeNull();
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('stops splashing the moment the owner upgrades, with no deploy', function () {
    $code = splashCode();
    $this->get("/x/{$code->slug}")->assertOk();

    Subscription::factory()->for($code->user)->create(['plan' => Plan::Regular]);

    // The subscription observer drops the cache entry, so the next scan of the same
    // printed paper is a straight 302.
    expect(Cache::get(RedirectController::cacheKey($code->slug)))->toBeNull();

    $this->get("/x/{$code->slug}")->assertRedirect('https://menu.warungmakan.test/daftar-menu');
});

it('starts splashing again when a package expires', function () {
    $code = splashCode(Plan::Regular);
    $this->get("/x/{$code->slug}")->assertRedirect();

    $code->user->subscription->extend(Package::ThreeMonths);
    $code->user->subscription->forceFill(['ends_at' => now()->subDay()])->save();

    $this->get("/x/{$code->slug}")->assertOk()->assertSee('menu.warungmakan.test');
});

it('escapes a destination host rather than trusting it', function () {
    $code = QrCode::factory()->create();
    // Only reachable for a row written outside Eloquent, which the renderer refuses —
    // but the splash prints this string, so it may never be printed unescaped.
    DB::table('qr_codes')->where('id', $code->id)->update([
        'destination' => json_encode(['dest_url' => 'https://"><script>alert(1)</script>.test/x']),
    ]);
    Cache::forget(RedirectController::cacheKey($code->slug));

    $this->get("/x/{$code->slug}")->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('refuses to name a host the browser will not go to', function (string $destination) {
    $code = QrCode::factory()->create();
    // Only a row written outside Eloquent can hold these; the renderer refuses them.
    DB::table('qr_codes')->where('id', $code->id)
        ->update(['destination' => json_encode(['dest_url' => $destination])]);
    Cache::forget(RedirectController::cacheKey($code->slug));

    // PHP reads the host as good.test, a browser follows WHATWG and goes to
    // evil.test. Naming the wrong site turns the one honest moment in the flow into
    // a lie, so the page is not shown at all.
    $this->get("/x/{$code->slug}")
        ->assertOk()
        ->assertSee(__('redirect.unavailable.title'))
        ->assertDontSee('good.test');
})->with([
    'backslash' => ['https://evil.test\\@good.test/login'],
    'userinfo' => ['https://good.test@evil.test/login'],
]);

it('refuses a destination that points back at another kodeqr link', function () {
    $ours = parse_url((string) config('app.url'), PHP_URL_HOST);

    // Behind a 302 the browser's hop cap ended a loop. Behind a meta refresh there is
    // no cap, and a lapsed code has neither a scan cap nor any recording to notice.
    expect(fn () => QrCode::factory()->create(['destination' => ['url' => "https://{$ours}/x/Abc123"]]))
        ->toThrow(InvalidArgumentException::class);
});

it('expires its cache entry when the package does, not six hours later', function () {
    $code = splashCode(Plan::Regular);
    $code->user->subscription->forceFill(['ends_at' => now()->addMinutes(5)])->saveQuietly();
    Cache::forget(RedirectController::cacheKey($code->slug));

    $this->get("/x/{$code->slug}")->assertRedirect();

    // Lapsing is a clock event, not a write, so no observer fires. Without the clamp
    // this entry would keep serving paid 302s — and recording scans billing.md says
    // must not be recorded — for the rest of the six hours.
    expect(Cache::get(RedirectController::cacheKey($code->slug)))->not->toBeNull();

    $this->travel(6)->minutes();

    expect(Cache::get(RedirectController::cacheKey($code->slug)))->toBeNull();
    $this->get("/x/{$code->slug}")->assertOk()->assertSee('menu.warungmakan.test');
});

it('gives the trust decision a heading', function () {
    $code = splashCode();

    // The one page in the flow that asks a scanner to judge something has to have a
    // heading landmark, not a styled paragraph.
    $this->get("/x/{$code->slug}")->assertSee('<h1 class="label">', escape: false);
});

it('never splashes a code that is not redirecting', function () {
    $code = splashCode();
    $code->status = QrCodeStatus::Paused;
    $code->save();

    $this->get("/x/{$code->slug}")
        ->assertStatus(410)
        ->assertDontSee(__('redirect.splash.title'));
});
