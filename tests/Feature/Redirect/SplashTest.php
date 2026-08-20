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
        ->assertSee('Buat QR gratis', escape: false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('shows the path after the host, so a shared host still tells the scanner apart', function () {
    // The whole reason the path is there: every warung on the street points its code
    // at the same three hosts, and the host alone cannot distinguish them.
    $code = splashCode(url: 'https://instagram.com/warungmakanbahagia');

    $this->get("/x/{$code->slug}")
        ->assertSee('instagram.com')
        ->assertSee('/warungmakanbahagia');
});

it('keeps the query string, which is the whole destination for a wa.me link', function () {
    $code = splashCode(url: 'https://wa.me/6281234567890?text=Halo');

    $this->get("/x/{$code->slug}")->assertSee('/6281234567890?text=Halo');
});

it('shows no path at all when there is nothing to say', function () {
    $code = splashCode(url: 'https://kodeqr.com/');

    // A bare "/" is not information, and it makes the host look truncated.
    preg_match('~<p class="host">(.*?)</p>~s', (string) $this->get("/x/{$code->slug}")->getContent(), $shown);

    expect($shown[1] ?? '')->toBe('<span class="domain">kodeqr.com</span>');
});

it('truncates a path long enough to bury the host', function () {
    // The attacker controls the path and not the host. A kilobyte of query string
    // must not be able to push the one trustworthy part of the string off the screen.
    $code = splashCode(url: 'https://evil.example/'.str_repeat('a', 4000));

    $html = $this->get("/x/{$code->slug}")->getContent();

    preg_match('~<p class="host">(.*?)</p>~s', (string) $html, $shown);

    expect($shown[1] ?? '')->toContain('evil.example')
        ->and(strlen($shown[1] ?? ''))->toBeLessThan(200)
        ->and($shown[1] ?? '')->toEndWith('…');
});

it('cuts with the same glyph the CSS clamp draws, and in the same weight', function () {
    // The clamp's ellipsis is rendered in the styles of the element carrying the
    // clamp, so the host must not be the thing carrying the weight — otherwise a cut
    // path ends in a bold near-black "..." that reads as emphasis.
    $html = (string) $this->get('/x/'.splashCode()->slug)->getContent();

    expect($html)->toMatch('~\.host \{[^}]*font-weight: 400;~')
        ->and($html)->toMatch('~\.host \.domain \{[^}]*font-weight: 600;~')
        ->and($html)->not->toContain('...');
});

it('never lets the displayed string diverge from where the button goes', function () {
    $url = 'https://menu.warungmakan.test/'.str_repeat('b', 4000);
    $code = splashCode(url: $url);

    // Display is truncated; the href and the refresh are not. A splash that shows one
    // destination and navigates to another is worse than no splash at all.
    $html = $this->get("/x/{$code->slug}")->getContent();

    expect($html)->toContain('href="'.e($url).'"')
        ->and($html)->toContain('url='.$url);
});

it('displays the authority the browser will actually use', function (string $url, string $shown) {
    $code = splashCode(url: $url);

    preg_match('~<p class="host">(.*?)</p>~s', (string) $this->get("/x/{$code->slug}")->getContent(), $host);

    // Asserted against the RENDERED TEXT, not against the href — checking the href
    // only proves the link works, which it would even if the page named a different
    // site entirely.
    expect(strip_tags($host[1] ?? ''))->toBe($shown);
})->with([
    // A non-default port is part of the origin: a different port can be a different
    // service. Naming the bare host for a link going to :8443 is a lie of omission.
    'port is shown' => ['https://toko.warungmakan.test:8443/menu', 'toko.warungmakan.test:8443/menu'],
    'no port' => ['https://toko.warungmakan.test/menu', 'toko.warungmakan.test/menu'],
    // No browser prints :443 or :80, so neither does the trust line.
    'explicit https default dropped' => ['https://toko.warungmakan.test:443/menu', 'toko.warungmakan.test/menu'],
    'explicit http default dropped' => ['http://toko.warungmakan.test:80/menu', 'toko.warungmakan.test/menu'],
    // A homograph host renders as punycode, the way an address bar shows it — the
    // bold span sits directly under a wordmark reading kodeqr.com.
    'homograph shown as punycode' => ['https://kоdeqr.com/menu', 'xn--kdeqr-jye.com/menu'],
]);

it('refuses a destination whose host it cannot name honestly', function () {
    // PHP hands back `%65vil.test`; a browser decodes it and goes to `evil.test`.
    // The renderer refuses it on write, so only a legacy row reaches here — and it
    // gets the unavailable page rather than a splash naming a host nobody visits.
    $code = splashCode();
    QrCode::withoutEvents(fn () => $code->forceFill([
        'destination' => ['url' => 'https://%65vil.test/', 'dest_url' => 'https://%65vil.test/'],
    ])->save());
    Cache::forget(RedirectController::cacheKey($code->slug));

    $this->get("/x/{$code->slug}")
        ->assertOk()
        ->assertSee(__('redirect.unavailable.title'))
        ->assertDontSee('evil.test');
});

it('keeps the splash itself under the size a scanner pays for', function () {
    // The other size guard covers a status page; the splash carries the progress bar
    // and the fallback on top of it. Measured on a typical destination — a long one
    // is the owner's payload, and the invariant for that is the next test.
    $url = 'https://www.tokopedia.com/warungmakanbahagia/paket-nasi-goreng-spesial?src=qr&utm_source=meja-12';

    $bytes = strlen((string) $this->get('/x/'.splashCode(url: $url)->slug)->getContent());

    expect($bytes)->toBeLessThan(8192);
});

it('carries the destination no more times than it has to', function () {
    // Twice, and only twice: the meta refresh and the fallback href, both of which a
    // stranded scanner needs. The displayed copy is truncated so it does not grow.
    // Making the host a link put a THIRD untruncated copy in the body and pushed an
    // ordinary Google Form URL past the size guard — that is what this pins.
    $tail = str_repeat('c', 1000);

    $short = strlen((string) $this->get('/x/'.splashCode(url: 'https://a.test/')->slug)->getContent());
    $long = strlen((string) $this->get('/x/'.splashCode(url: 'https://a.test/'.$tail)->slug)->getContent());

    // Two full copies, plus the displayed copy, which is capped at 96 chars however
    // long the destination is. A third full copy would add another 1000.
    expect($long - $short)->toBeLessThanOrEqual(2 * strlen($tail) + 128);
});

it('keeps its reasoning out of the response body', function () {
    // Blade comments are stripped at compile time; CSS /* */ comments are not, and
    // this page has no build step to strip them. Every word of rationale left in the
    // <style> block is downloaded by every free-tier scan on cellular.
    $html = (string) $this->get('/x/'.splashCode()->slug)->getContent();

    expect($html)->not->toContain('/*');
});

it('leaves a way through when a browser refuses the meta refresh', function () {
    // Some in-app browsers drop meta refresh, and a scanner stranded in front of
    // printed paper cannot go back.
    $code = splashCode();

    $this->get("/x/{$code->slug}")
        ->assertSee('<a class="fallback" href="https://menu.warungmakan.test/daftar-menu"', escape: false)
        ->assertSee(__('redirect.splash.action'));
});

it('fills the bar over exactly the wait it describes', function () {
    // The bar and the meta refresh are two descriptions of one event. A bar that
    // finishes at a different moment than the redirect fires is worse than no bar.
    $html = (string) $this->get('/x/'.splashCode()->slug)->getContent();

    preg_match('~content="([0-9.]+);url=~', $html, $refresh);

    expect($html)->toContain('--drain: '.$refresh[1].'s');
});

it('hides the fallback until after the redirect should already have fired', function () {
    // The whole cost of the button was existing during the 2.5s it was not needed.
    // If these two ever cross, the page grows a CTA it spent this long removing.
    $html = (string) $this->get('/x/'.splashCode()->slug)->getContent();

    preg_match('~content="([0-9.]+);url=~', $html, $refresh);
    preg_match('~--reveal: ([0-9.]+)s~', $html, $reveal);

    expect($html)->toContain('@keyframes hold { from { display: none; }')
        ->and((float) $reveal[1])->toBeGreaterThan((float) $refresh[1]);
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
    // tells a business's customers that the business did not pay. The footer is the
    // same one every other page carries — no billing, and nothing addressed to an
    // owner who is not the one holding the phone.
    $response->assertSee('Buat QR gratis', escape: false)
        ->assertDontSee('berakhir')
        ->assertDontSee('perpanjang')
        ->assertDontSee('Paket')
        ->assertDontSee('masuk untuk mengelola');
});

it('shows one footer on every scanner-facing page, whatever the plan', function () {
    // A per-plan footer is a per-plan leak: the reader is a stranger on all of them.
    $free = $this->get('/x/'.splashCode()->slug)->getContent();
    $lapsed = $this->get('/x/'.splashCode(Plan::Lapsed)->slug)->getContent();
    $blocked = $this->get('/x/'.tap(splashCode(), function (QrCode $code): void {
        $code->status = QrCodeStatus::Blocked;
        $code->save();
    })->slug)->getContent();

    $footer = '<footer>'.__('redirect.footer', ['brand' => '<a href="https://kodeqr.com">kodeqr.com</a>']).'</footer>';

    expect($free)->toContain($footer)
        ->and($lapsed)->toContain($footer)
        ->and($blocked)->toContain($footer);
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
