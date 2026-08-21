<?php

declare(strict_types=1);

use App\Mail\RedirectCanaryFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

/**
 * One dynamic stub, held in a static, rather than a fresh Http::fake() per call.
 * `Http::fake()` MERGES stubs and the earliest match wins, so re-faking the same URL
 * mid-test silently keeps the first answer — which made a "recovery" test go on
 * failing and would have hidden exactly the bug it was written to catch.
 *
 * @param  array{status: int, location: ?string, edge: bool}|null  $set
 * @return array{status: int, location: ?string, edge: bool}
 */
function canaryAnswer(?array $set = null): array
{
    static $state = ['status' => 302, 'location' => 'https://kodeqr.com/canary', 'edge' => true];

    if ($set !== null) {
        $state = $set;
    }

    return $state;
}

function respondsWith(int $status, ?string $location = 'https://kodeqr.com/canary', bool $viaEdge = true): void
{
    canaryAnswer(['status' => $status, 'location' => $location, 'edge' => $viaEdge]);
}

beforeEach(function () {
    Mail::fake();
    Cache::store('file')->forget('health:failures');
    Cache::store('file')->forget('health:alerted-at');
    config(['health.url' => 'https://kodeqr.com', 'health.alert_address' => 'alerts@kodeqr.test']);
    respondsWith(302);

    Http::fake(function () {
        $answer = canaryAnswer();
        $headers = $answer['location'] === null ? [] : ['Location' => $answer['location']];

        if ($answer['edge']) {
            $headers['cf-ray'] = '8a1b2c3d4e5f6789-SIN';
        }

        return Http::response('', $answer['status'], $headers);
    });
});

it('passes when the canary redirects to where it should', function () {
    respondsWith(302);

    $this->artisan('redirect:health')->assertSuccessful();

    Mail::assertNothingSent();
});

it('fails when the canary does not redirect', function () {
    respondsWith(200, null);

    $this->artisan('redirect:health')->assertFailed();
});

it('fails when the canary redirects somewhere else', function () {
    // A poisoned cache, or a destination edited by someone with database access,
    // still answers 302 — and every scanner lands somewhere we did not choose.
    respondsWith(302, 'https://attacker.example/');

    $this->artisan('redirect:health')->assertFailed();
});

it('says nothing on the first failure and alerts on the second', function () {
    respondsWith(500, null);

    $this->artisan('redirect:health')->assertFailed();
    Mail::assertNothingSent();

    $this->artisan('redirect:health')->assertFailed();
    Mail::assertSent(RedirectCanaryFailed::class, fn (RedirectCanaryFailed $mail): bool => $mail->failures === 2
        && $mail->hasTo('alerts@kodeqr.test'));
});

it('reminds without shouting for the length of an outage', function () {
    respondsWith(500, null);

    // 32 runs: the alert at 2, then one reminder at 32. An earlier version of this
    // test stopped at 12 and asserted exactly one mail — which would have passed just
    // as happily against an implementation that never reminded anybody again.
    for ($i = 0; $i < 32; $i++) {
        $this->artisan('redirect:health');
    }

    // An alert channel that fires every minute becomes a filter rule, and then the
    // next outage is silent. One that never repeats is a missed page.
    Mail::assertSentCount(2);
});

it('tries again next minute when the alert reached nobody', function () {
    respondsWith(500, null);
    config(['health.alert_address' => '']);

    $this->artisan('redirect:health');
    $this->artisan('redirect:health');
    $this->artisan('redirect:health');

    // Nothing went out, so nothing may be recorded as having gone out. Marking the
    // alert sent regardless bought thirty minutes of silence from one SMTP hiccup or
    // one missing env var — during an outage, with nobody told.
    expect(Cache::store('file')->get('health:alerted-at'))->toBeNull();

    config(['health.alert_address' => 'alerts@kodeqr.test']);
    $this->artisan('redirect:health');

    Mail::assertSent(RedirectCanaryFailed::class);
});

it('starts counting again after a recovery', function () {
    respondsWith(500, null);
    $this->artisan('redirect:health');

    respondsWith(302);
    $this->artisan('redirect:health')->assertSuccessful();

    respondsWith(500, null);
    $this->artisan('redirect:health')->assertFailed();

    // One failure after a good run is one failure, not the second of a pair.
    Mail::assertNothingSent();
});

it('counts failures somewhere Redis going down cannot erase', function () {
    respondsWith(500, null);
    $this->artisan('redirect:health');

    // Redis being unreachable IS one of the outages this exists to catch. A counter
    // living there would reset to 1 every run of that outage and never alert.
    expect(Cache::store('file')->get('health:failures'))->toBe(1);
});

it('refuses to run against a loopback address in production', function (string $url) {
    config(['health.url' => $url]);
    app()->detectEnvironment(fn () => 'production');

    // A canary that never leaves the box reports green through an expired
    // certificate, a Cloudflare rule and a DNS change alike — and is believed.
    $this->artisan('redirect:health')->assertFailed();

    Http::assertNothingSent();
})->with([
    'localhost' => 'http://localhost:8000',
    'loopback ip' => 'http://127.0.0.1:8000',
    'herd domain' => 'http://kodeqr.test',
]);

it('fails when the response never went through the edge', function () {
    // On Laravel Cloud, APP_URL is routinely the *.laravel.cloud ORIGIN name: publicly
    // routable, passes every other check, and nowhere near Cloudflare. A canary
    // pointed there reports green through an edge rule, a DNS change or an expired
    // edge certificate — the exact failure the task calls "worse than none".
    respondsWith(302, viaEdge: false);

    $this->artisan('redirect:health')->assertFailed();
});

it('can be told there is no edge to check for', function () {
    config(['health.edge_header' => '']);
    respondsWith(302, viaEdge: false);

    $this->artisan('redirect:health')->assertSuccessful();
});

it('mails about a misconfigured canary, not just the log', function () {
    // "Running" every minute while the redirect is down for days, with the only
    // record a log line nobody reads at 3am, is the failure config/health.php spends
    // a paragraph arguing against.
    config(['health.url' => 'http://127.0.0.1:8000']);

    $this->artisan('redirect:health')->assertFailed();
    $this->artisan('redirect:health')->assertFailed();

    Mail::assertSent(RedirectCanaryFailed::class);
    Http::assertNothingSent();
});

it('counts a timeout toward the latency percentile', function () {
    Redis::connection()->del('health:latency:'.now()->timezone('Asia/Jakarta')->toDateString());
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $this->artisan('redirect:health')->assertFailed();

    // Sampling only successful runs made the percentile survivorship-biased in the
    // worst direction: a day spent at 4.9s reported a p95 built from the runs that
    // happened to stay fast.
    expect(Redis::connection()->llen('health:latency:'.now()->timezone('Asia/Jakarta')->toDateString()))->toBe(1);
})->skip(fn (): bool => ! extension_loaded('redis'), 'Requires Redis.');

it('samples latency for the daily percentile', function () {
    respondsWith(302);
    $key = 'health:latency:'.now()->timezone('Asia/Jakarta')->toDateString();
    Redis::connection()->del($key);

    $this->artisan('redirect:health');

    expect(Redis::connection()->llen($key))->toBe(1);
})->skip(fn (): bool => ! extension_loaded('redis'), 'Requires Redis.');
