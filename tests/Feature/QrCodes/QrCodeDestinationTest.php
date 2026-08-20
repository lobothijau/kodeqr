<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Http\Controllers\RedirectController;
use App\Models\QrCode;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $destination
 */
function whatsappCode(array $destination): QrCode
{
    return QrCode::factory()->create(['type' => QrCodeType::Whatsapp, 'destination' => $destination]);
}

it('renders a whatsapp destination at save time', function () {
    $code = whatsappCode(['phone' => '08123456789', 'text' => 'Halo']);

    // Acceptance: the local trunk prefix becomes the country code, and the redirect
    // path finds a finished URL rather than three fields to assemble (I1).
    expect($code->fresh()->destination['dest_url'])->toBe('https://wa.me/628123456789?text=Halo');
});

it('normalizes every shape an Indonesian phone number arrives in', function (string $input, string $expected) {
    expect(whatsappCode(['phone' => $input])->fresh()->destination['phone'])->toBe($expected);
})->with([
    'local trunk' => ['08123456789', '628123456789'],
    // What people type under a "+62" field label. Untouched it is country code 81 —
    // Japan — and the printed code sends every scan to a stranger.
    'no trunk prefix' => ['8123456789', '628123456789'],
    // Long enough to carry its own country code, so it is left where it was dialled.
    'foreign number in full' => ['8613800138000', '8613800138000'],
    'spaces and dashes' => ['+62 812-3456', '628123456'],
    'plain country code' => ['628123456789', '628123456789'],
    // 00 is the international dial prefix. Stripping it AFTER the leading-zero rule
    // would turn 0062... into 62062..., a number that does not exist.
    'international prefix' => ['006281234567', '6281234567'],
    // 00 says the country code is already there, so the domestic 8-rule must not
    // reach it — 008190… is Japan, and 628190… is an Indonesian stranger.
    'international prefix, foreign' => ['008190123456', '8190123456'],
    'parentheses' => ['(0812) 3456-789', '628123456789'],
]);

it('refuses a phone number that cannot be dialled', function (string $phone) {
    expect(fn () => whatsappCode(['phone' => $phone]))->toThrow(InvalidArgumentException::class);
})->with([
    'too short' => ['0812345'],
    'empty' => [''],
    'letters only' => ['hubungi kami'],
    'longer than E.164' => ['62812345678901234'],
]);

it('percent-encodes the message so & and emoji survive the scan', function () {
    $code = whatsappCode(['phone' => '08123456789', 'text' => 'Promo & diskon 👋']);

    $destUrl = $code->fresh()->destination['dest_url'];

    expect($destUrl)->toBe('https://wa.me/628123456789?text=Promo%20%26%20diskon%20%F0%9F%91%8B')
        // rawurlencode, not urlencode: a `+` here is rendered literally by anything
        // parsing the query per RFC 3986, and the message arrives with plus signs
        // where the spaces were.
        ->and($destUrl)->not->toContain('+')
        ->and(rawurldecode((string) parse_url($destUrl, PHP_URL_QUERY)))->toBe('text=Promo & diskon 👋');
});

it('omits the query entirely when there is no message', function (array $destination) {
    expect(whatsappCode($destination)->fresh()->destination['dest_url'])->toBe('https://wa.me/628123456789');
})->with([
    'absent' => [['phone' => '08123456789']],
    'empty' => [['phone' => '08123456789', 'text' => '']],
    'whitespace' => [['phone' => '08123456789', 'text' => "  \n "]],
]);

it('discards a caller-supplied dest_url on create and on edit', function () {
    $code = whatsappCode([
        'phone' => '08123456789',
        'text' => 'Halo',
        'dest_url' => 'https://evil.test/drain',
    ]);

    // `destination` is fillable as a whole array, so a crafted payload would otherwise
    // walk a dest_url straight past validation and point the code anywhere.
    expect($code->fresh()->destination['dest_url'])->toBe('https://wa.me/628123456789?text=Halo');

    $code->update(['destination' => ['phone' => '08999999999', 'dest_url' => 'https://evil.test/drain']]);

    expect($code->fresh()->destination['dest_url'])->toBe('https://wa.me/628999999999');
});

it('re-renders when the owner edits the destination', function () {
    $code = whatsappCode(['phone' => '08123456789', 'text' => 'Halo']);

    $code->update(['destination' => ['phone' => '08123456789', 'text' => 'Menu baru']]);

    // The edit path is where a save-time renderer usually rots: create is covered,
    // update quietly keeps yesterday's URL.
    expect($code->fresh()->destination['dest_url'])->toBe('https://wa.me/628123456789?text=Menu%20baru');
});

it('mirrors a url destination into dest_url', function () {
    $code = QrCode::factory()->create(['destination' => ['url' => '  https://kodeqr.test/menu  ']]);

    expect($code->fresh()->destination)
        ->toBe(['url' => 'https://kodeqr.test/menu', 'dest_url' => 'https://kodeqr.test/menu']);
});

it('assumes https for a url typed without a scheme', function (string $input, string $expected) {
    expect(QrCode::factory()->create(['destination' => ['url' => $input]])->fresh()->destination['dest_url'])
        ->toBe($expected);
})->with([
    'bare host' => ['kodeqr.test/menu', 'https://kodeqr.test/menu'],
    'host and port' => ['kodeqr.test:8080/menu', 'https://kodeqr.test:8080/menu'],
    'protocol relative' => ['//kodeqr.test/menu', 'https://kodeqr.test/menu'],
]);

it('refuses a destination field that is not a string', function (mixed $url) {
    // `['url' => true]` coerced to "1" would persist as https://1: a malformed
    // payload wearing the shape of a deliberate destination.
    expect(fn () => QrCode::factory()->create(['destination' => ['url' => $url]]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'boolean' => [true],
    'integer' => [0],
    'array' => [['https://kodeqr.test']],
]);

it('still lets a code with an unrenderable stored destination be blocked', function () {
    $code = QrCode::factory()->create();
    DB::table('qr_codes')->where('id', $code->id)
        ->update(['destination' => json_encode(['url' => 'ftp://legacy.test/file'])]);

    $code = $code->fresh();
    $code->status = QrCodeStatus::Blocked;

    // Constraints 5 and 8: M1-T5's abuse path and the quota job have to be able to
    // write a row whose destination this renderer would refuse. The code you most
    // need to block is exactly the one whose destination is wrong.
    expect(fn () => $code->save())->not->toThrow(InvalidArgumentException::class)
        ->and($code->fresh()->status)->toBe(QrCodeStatus::Blocked)
        ->and($code->fresh()->destination['url'])->toBe('ftp://legacy.test/file');
});

it('leaves a partially hydrated model alone', function () {
    $code = whatsappCode(['phone' => '08123456789']);

    $partial = QrCode::query()->select(['id', 'slug', 'last_scanned_at'])->find($code->id);
    $partial->last_scanned_at = now();

    // M1-T4 bumps last_scanned_at off a narrow select. There is no type or
    // destination loaded to render, and asking for one is a TypeError mid-pipeline.
    expect(fn () => $partial->save())->not->toThrow(TypeError::class)
        ->and($code->fresh()->destination['dest_url'])->toBe('https://wa.me/628123456789');
});

it('refuses a url that must never reach a Location header', function (string $url) {
    expect(fn () => QrCode::factory()->create(['destination' => ['url' => $url]]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'file' => ['file:///etc/passwd'],
    'header injection' => ["https://kodeqr.test/\r\nSet-Cookie: a=b"],
    'no host' => ['https://'],
    'empty' => ['   '],
    // PHP reads the host here as safe.test; a browser follows WHATWG, treats the
    // backslash as a slash, and goes to evil.test. Anything that checks one and
    // navigates to the other is a hole, so neither gets the chance.
    'backslash authority' => ['https://evil.test\\@safe.test/login'],
    // The oldest phishing disguise there is: reads as the bank, goes to evil.test.
    'userinfo' => ['https://bank.co.id@evil.test/masuk'],
    'userinfo with password' => ['https://a:b@evil.test/'],
]);

it('still allows an at sign in the path, where handles live', function () {
    $code = QrCode::factory()->create(['destination' => ['url' => 'https://x.com/@kodeqr']]);

    expect($code->fresh()->destination['dest_url'])->toBe('https://x.com/@kodeqr');
});

it('refuses to persist a type nothing can render yet', function (QrCodeType $type) {
    // M3 and M4 add these. Until then a row of this type cannot exist at all, which
    // is better than one that persists with no dest_url and dead-ends a scan.
    expect(fn () => QrCode::factory()->create(['type' => $type, 'destination' => ['url' => 'https://kodeqr.test']]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'file' => [QrCodeType::File],
    'vcard' => [QrCodeType::Vcard],
    'linkpage' => [QrCodeType::Linkpage],
]);

it('sends a scanner to the rendered whatsapp url without building it per request', function () {
    $code = whatsappCode(['phone' => '08123456789', 'text' => 'Halo & selamat datang']);
    // Paid: a free owner would see the interstitial rather than a Location header.
    Subscription::factory()->for($code->user)->create(['plan' => Plan::Regular]);
    Cache::forget(RedirectController::cacheKey($code->slug));

    $this->get("/x/{$code->slug}")
        ->assertRedirect('https://wa.me/628123456789?text=Halo%20%26%20selamat%20datang');

    DB::enableQueryLog();
    DB::flushQueryLog();

    // I1 end to end: the warm path reads a stored string and issues no SQL. Nothing
    // about phones or percent-encoding happens while a scanner waits.
    $this->get("/x/{$code->slug}")->assertRedirect();

    expect(DB::getQueryLog())->toBe([]);
});
