<?php

declare(strict_types=1);

use App\Enums\AbuseSource;
use App\Enums\QrCodeStatus;
use App\Jobs\RecheckDestination;
use App\Models\AbuseFlag;
use App\Models\QrCode;
use App\Rules\SafeDestination;
use App\Services\DestinationRenderer;
use App\Services\ThreatCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare's answer for a host in its threat intelligence: 0.0.0.0, and an EDE 16
 * saying the answer was filtered. 0.0.0.0 alone is also what a host that resolves
 * nowhere returns, which is why the code and not the address is the signal.
 *
 * @return array<string, mixed>
 */
function censoredAnswer(): array
{
    return [
        'Status' => 0,
        'Question' => [['name' => 'phishing.test', 'type' => 1]],
        'Answer' => [['name' => 'phishing.test', 'type' => 1, 'TTL' => 60, 'data' => '0.0.0.0']],
        'Comment' => ['EDE(16): Censored'],
    ];
}

/**
 * @return array<string, mixed>
 */
function cleanAnswer(): array
{
    return [
        'Status' => 0,
        'Question' => [['name' => 'tokopedia.com', 'type' => 1]],
        'Answer' => [['name' => 'tokopedia.com', 'type' => 1, 'TTL' => 49, 'data' => '47.74.244.18']],
    ];
}

function validateDestination(string $url): Illuminate\Validation\Validator
{
    return Validator::make(
        ['url' => $url],
        ['url' => [new SafeDestination(app(ThreatCheck::class))]],
    );
}

function recheck(string $url, int $attempt = 1): void
{
    (new RecheckDestination($url, $attempt))->handle(
        app(ThreatCheck::class),
        app(DestinationRenderer::class),
    );
}

it('refuses a destination the resolver reports as filtered', function () {
    Http::fake(['*' => Http::response(censoredAnswer())]);

    $validator = validateDestination('https://phishing.test/bca-login');

    expect($validator->fails())->toBeTrue()
        // Constraint 10: the owner reads this in Bahasa, not a framework key — and
        // it attributes the verdict rather than claiming it. We ran a lookup; the
        // finding is the provider's, and an owner who disputes it needs to know whose.
        ->and($validator->errors()->first('url'))->toBe(
            'Domain ini ditandai berbahaya oleh layanan keamanan Cloudflare, jadi belum bisa dipakai sebagai tujuan. '
            .'Jika menurut Anda ini keliru, hubungi kami.'
        );
});

it('records every refusal so a URL cannot be retried until it sticks', function () {
    Http::fake(['*' => Http::response(censoredAnswer())]);

    validateDestination('https://phishing.test/bca-login')->fails();

    $flag = AbuseFlag::query()->sole();

    expect($flag->source)->toBe(AbuseSource::ThreatCheck)
        ->and($flag->url)->toBe('https://phishing.test/bca-login')
        ->and($flag->threat_type)->toBe('dns_filtered')
        ->and($flag->qr_code_id)->toBeNull();
});

it('lets a clean destination through without a flag', function () {
    Http::fake(['*' => Http::response(cleanAnswer())]);

    expect(validateDestination('https://tokopedia.com/toko')->fails())->toBeFalse()
        ->and(AbuseFlag::query()->count())->toBe(0);
});

it('asks the resolver about the host, and only once per host', function () {
    Http::fake(['*' => Http::response(cleanAnswer())]);

    validateDestination('https://tokopedia.com/a')->fails();
    validateDestination('https://tokopedia.com/b')->fails();

    // Owners save repeatedly while editing. The verdict is about the host, so the
    // second save is free rather than another round-trip on their save path.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'name=tokopedia.com')
        && str_contains($request->url(), 'type=A'));
});

it('saves the code but queues a recheck when the resolver cannot answer', function () {
    Bus::fake();
    Http::fake(['*' => Http::response('', 503)]);

    // Fail-open: an outage at the checker must not stop every signup in the country.
    // The recheck is what keeps I5 true rather than merely intended.
    expect(validateDestination('https://unknown.test/menu')->fails())->toBeFalse();

    Bus::assertDispatched(RecheckDestination::class);
});

it('queues a recheck when the resolver times out', function () {
    Bus::fake();
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(validateDestination('https://unknown.test/menu')->fails())->toBeFalse();

    Bus::assertDispatched(RecheckDestination::class);
});

it('does not cache a verdict it never got', function () {
    // Bus::fake so the recheck this dispatches does not eat the second response.
    Bus::fake();
    Http::fakeSequence()->pushStatus(503)->push(censoredAnswer());

    validateDestination('https://unknown.test/menu')->fails();

    // An outage must not be remembered as "safe" for a day. The next save asks again.
    expect(validateDestination('https://unknown.test/menu')->fails())->toBeTrue();
});

it('blocks the code when the recheck finds what the first check could not', function () {
    $code = QrCode::factory()->create(['destination' => ['url' => 'https://phishing.test/bca']]);
    Http::fake(['*' => Http::response(censoredAnswer())]);

    recheck('https://phishing.test/bca');

    expect($code->fresh()->status)->toBe(QrCodeStatus::Blocked)
        ->and(AbuseFlag::query()->sole()->qr_code_id)->toBe($code->id);

    // I5 end to end: the observer's cache forget means the next scan of the printed
    // code gets the abuse page, not the destination.
    $this->get("/x/{$code->slug}")
        ->assertStatus(Response::HTTP_GONE)
        ->assertSee(__('redirect.blocked.title'));
});

it('leaves a code alone when the recheck comes back clean', function () {
    $code = QrCode::factory()->create(['destination' => ['url' => 'https://tokopedia.com/toko']]);
    Http::fake(['*' => Http::response(cleanAnswer())]);

    recheck('https://tokopedia.com/toko');

    expect($code->fresh()->status)->toBe(QrCodeStatus::Active)
        ->and(AbuseFlag::query()->count())->toBe(0);
});

it('checks the URL that will be stored, not the one that was typed', function () {
    Http::fake(['*' => Http::response(censoredAnswer())]);

    // The renderer prepends https to scheme-less input, so this is saved as a working
    // destination. Parsing the raw string finds no host at all — every scheme-less
    // URL would have been waved straight through unchecked.
    expect(validateDestination('malware.example/path')->fails())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'name=malware.example'));
});

it('blocks a code created during an outage, which has no id to be told about', function () {
    Bus::fake();
    // Unreachable at save, answering by the time the recheck runs.
    Http::fakeSequence()->pushStatus(503)->push(censoredAnswer());

    // Validation runs before the row exists, so the create path can never hand the
    // job an id. A recheck that could only record a URL would close nothing.
    expect(validateDestination('phishing.test/bca')->fails())->toBeFalse();

    $code = QrCode::factory()->create(['destination' => ['url' => 'phishing.test/bca']]);

    recheck('phishing.test/bca');

    // Matched on the stored, normalised destination — the typed string never equals it.
    expect($code->fresh()->status)->toBe(QrCodeStatus::Blocked);
});

it('blocks a paused code too, before its owner can resume it', function () {
    $code = QrCode::factory()->create([
        'status' => QrCodeStatus::Paused,
        'destination' => ['url' => 'https://phishing.test/bca'],
    ]);
    Http::fake(['*' => Http::response(censoredAnswer())]);

    recheck('https://phishing.test/bca');

    // Constraint 8 guarantees a paused code starts redirecting again one day. Leaving
    // a confirmed-malicious destination armed until then is not a safe state.
    expect($code->fresh()->status)->toBe(QrCodeStatus::Blocked);
});

it('keeps asking while the resolver stays unreachable', function () {
    Bus::fake();
    Http::fake(['*' => Http::response('', 503)]);

    recheck('https://unknown.test/menu');

    // One retry that treats "still cannot tell" as "fine" leaves a malicious code
    // redirecting for ever, and outages routinely outlast ten minutes.
    Bus::assertDispatched(RecheckDestination::class);
});

it('gives up loudly rather than retrying for ever', function () {
    Bus::fake();
    Log::spy();
    Http::fake(['*' => Http::response('', 503)]);

    recheck('https://unknown.test/menu', attempt: 5);

    Bus::assertNotDispatched(RecheckDestination::class);
    Log::shouldHaveReceived('critical');
});

it('does not block a code whose destination was edited after the recheck was queued', function () {
    $code = QrCode::factory()->create(['destination' => ['url' => 'https://tokopedia.com/toko']]);
    Http::fake(['*' => Http::response(censoredAnswer())]);

    recheck('https://phishing.test/old');

    // The owner already moved off the bad URL. Blocking now punishes them for a
    // destination that no longer exists — and the flag still records what happened.
    expect($code->fresh()->status)->toBe(QrCodeStatus::Active)
        ->and(AbuseFlag::query()->count())->toBe(1);
});

it('treats an answer it cannot read as unknown, never as clean', function (mixed $body) {
    Bus::fake();
    Http::fake(['*' => Http::response($body)]);

    // A 200 is not an answer. A captive portal, a proxy error page and a SERVFAIL all
    // arrive as 200-with-something; caching any of them as clean for a day is a free
    // pass for whatever domain was being asked about.
    expect(validateDestination('https://unknown.test/menu')->fails())->toBeFalse();

    Bus::assertDispatched(RecheckDestination::class);
})->with([
    'servfail' => [['Status' => 2, 'Question' => []]],
    'proxy html' => ['<html>captive portal</html>'],
    'error json' => [['error' => 'temporary upstream failure']],
]);

it('detects the filtered code when the resolver returns a bare string comment', function () {
    Http::fake(['*' => Http::response([
        'Status' => 0,
        'Answer' => [['name' => 'phishing.test', 'type' => 1, 'TTL' => 60, 'data' => '0.0.0.0']],
        'Comment' => 'EDE(16): Censored',
    ])]);

    // Iterating a string raises a warning, which Laravel turns into an exception,
    // which the catch would have filed as "resolver unavailable" — reporting every
    // censored domain as unknown and letting all of them through.
    expect(validateDestination('https://phishing.test/bca')->fails())->toBeTrue();
});

it('remembers what the finding was, not merely that there was one', function () {
    Http::fake(['*' => Http::response(censoredAnswer())]);

    validateDestination('https://phishing.test/a')->fails();
    validateDestination('https://phishing.test/b')->fails();

    // abuse_flags.threat_type has to say what the threat was on the hundredth
    // refusal as on the first; M1-T7 reads this table too.
    expect(AbuseFlag::query()->pluck('threat_type')->all())->toBe(['dns_filtered', 'dns_filtered']);
});

it('never asks a resolver about a URL with no host', function () {
    Http::fake();

    expect(validateDestination('not a url')->fails())->toBeFalse();

    Http::assertNothingSent();
});
