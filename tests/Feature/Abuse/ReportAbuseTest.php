<?php

declare(strict_types=1);

use App\Enums\AbuseReason;
use App\Enums\AbuseSource;
use App\Mail\AbuseReported;
use App\Models\AbuseFlag;
use App\Models\QrCode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Mail::fake();
});

function submit(array $overrides = []): TestResponse
{
    return test()->post('/laporkan', array_merge([
        'report' => 'AbCdE2',
        'reason' => AbuseReason::Phishing->value,
    ], $overrides));
}

it('shows the form', function () {
    $this->get('/laporkan')
        ->assertOk()
        ->assertSee(__('abuse.title'))
        ->assertSee(__('abuse.submit'));
});

it('records a report against a code that exists', function () {
    $code = QrCode::factory()->create();

    submit(['report' => $code->slug, 'reason' => AbuseReason::Penipuan->value, 'reporter_email' => 'saya@warung.test'])
        ->assertRedirect(route('abuse.report.show'))
        ->assertSessionHas('status', 'reported');

    $flag = AbuseFlag::sole();

    expect($flag->qr_code_id)->toBe($code->id)
        ->and($flag->source)->toBe(AbuseSource::Report)
        ->and($flag->reason)->toBe(AbuseReason::Penipuan)
        ->and($flag->reporter_email)->toBe('saya@warung.test')
        ->and($flag->url)->toBe(route('redirect.show', ['slug' => $code->slug]));

    Mail::assertQueued(AbuseReported::class);
});

it('is not an oracle for which slugs are live', function () {
    // THE thing this endpoint exists to avoid: submit, diff what comes back, learn
    // which slugs are real. The 302 is nearly empty, so comparing only its status and
    // Location proves almost nothing — what a reporter actually sees is the page they
    // land on, so the whole rendered body is compared, three ways.
    QrCode::factory()->create(['slug' => 'Liv3xK']);
    $deleted = QrCode::factory()->create(['slug' => 'De1eTd']);
    $deleted->delete();

    $render = function (string $slug): array {
        $response = $this->followingRedirects()
            ->post('/laporkan', ['report' => $slug, 'reason' => AbuseReason::Phishing->value]);

        $headers = collect($response->headers->all())
            // Set-Cookie carries the rotating session id and Date is a clock.
            ->except(['set-cookie', 'date'])
            ->toArray();

        return [$response->getStatusCode(), $headers, $response->getContent()];
    };

    $live = $render('Liv3xK');

    expect($render('De1eTd'))->toBe($live)
        ->and($render('Zzz9Yx'))->toBe($live);
});

it('says nothing about the outcome it cannot promise', function () {
    // The copy is part of the surface: "we could not find that code", or "blocked",
    // or any acknowledgement of what was found, is the same leak written in Bahasa.
    $body = (string) $this->followingRedirects()
        ->post('/laporkan', ['report' => 'Zzz9Yx', 'reason' => AbuseReason::Malware->value])
        ->getContent();

    expect($body)->toContain(__('abuse.sent.title'))
        ->and($body)->not->toContain('tidak ditemukan')
        ->and($body)->not->toContain('Zzz9Yx');
});

it('still stores a report whose slug never existed', function () {
    submit(['report' => 'Zzz9Yx']);

    $flag = AbuseFlag::sole();

    // A report on an unknown slug is not noise: a scam sticker over a dead code, or a
    // slug the reporter mistyped by one character, are both worth a human's minute.
    expect($flag->qr_code_id)->toBeNull()
        ->and($flag->url)->toBe(route('redirect.show', ['slug' => 'Zzz9Yx']));
});

it('reads the slug out of whatever the reporter managed to paste', function (string $template) {
    $code = QrCode::factory()->create(['slug' => 'Ab3xK9']);
    $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    submit(['report' => str_replace('{host}', $host, $template)]);

    expect(AbuseFlag::sole()->qr_code_id)->toBe($code->id);
})->with([
    'bare slug' => 'Ab3xK9',
    'full url' => 'https://{host}/x/Ab3xK9',
    'no scheme' => '{host}/x/Ab3xK9',
    'trailing slash' => 'https://{host}/x/Ab3xK9/',
    'surrounding space' => '  https://{host}/x/Ab3xK9  ',
    'scanner app query' => 'https://{host}/x/Ab3xK9?utm_source=camera',
    'www prefix' => 'https://www.{host}/x/Ab3xK9',
]);

it('offers the QR picker without making it the only way in', function () {
    $html = (string) $this->get('/laporkan')->getContent();

    // Hidden until the browser proves it can decode, and the text field is always
    // there underneath. A device without BarcodeDetector — Safari, at time of
    // writing — must still be able to file a report.
    expect($html)->toContain('id="qr-scan" hidden')
        ->and($html)->toContain('accept="image/*"')
        ->and($html)->toContain('id="report"');
});

it('never asks the server to accept an image', function () {
    // The decode happens on the device. If a route ever starts taking the file, this
    // is the test that should stop it: no upload endpoint, no image parsing on our
    // side, and no stored photographs of the places people found these stickers.
    $html = (string) $this->get('/laporkan')->getContent();

    preg_match('~<form[^>]*>~', $html, $form);

    expect($form[0] ?? '')->not->toContain('multipart/form-data')
        ->and($html)->toContain('name="report"');

    // And the picker carries no name, so a browser cannot post the file even if the
    // encoding changed underneath it.
    preg_match('~<input[^>]*id="qr-image"[^>]*>~', $html, $picker);
    expect($picker[0] ?? '')->not->toContain('name=');
});

it('accepts what the picker writes into the field', function () {
    // The decoder yields the raw QR payload — a full /x/ URL. It goes in unparsed
    // because the server already knows how to read one, and a second copy of that
    // logic in JavaScript is a second place for it to be wrong.
    $code = QrCode::factory()->create(['slug' => 'Ab3xK9']);
    $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    submit(['report' => "https://{$host}/x/Ab3xK9"]);

    expect(AbuseFlag::sole()->qr_code_id)->toBe($code->id);
});

it('prefills the code so nobody has to type a slug', function () {
    // The reporter has just scanned the thing and is looking at a page we rendered,
    // so we already know which code it is. In an in-app browser there is often no
    // address bar to copy from even if they wanted to.
    $this->get('/laporkan?kode=Ab3xK9')
        ->assertOk()
        ->assertSee('value="Ab3xK9"', escape: false);
});

it('will not echo whatever a link puts in the query string', function (string $kode) {
    // Public page, attacker-controlled parameter. Prefilling arbitrary text would put
    // anything they like into a field an operator later reads and acts on.
    $html = (string) $this->get('/laporkan?kode='.urlencode($kode))->getContent();

    preg_match('~<input[^>]*id="report".*?>~s', $html, $input);

    expect($input[0] ?? '')->toContain('value=""');
})->with([
    'markup' => '<script>alert(1)</script>',
    'a url' => 'https://attacker.example/x/Ab3xK9',
    'too long' => 'Ab3xK9Ab3xK9',
    'wrong alphabet' => 'ABC0IL',
]);

it('lets a typed value win over the prefill', function () {
    // Validation failed and the reporter is looking at their own text again; the
    // query string must not overwrite it on the way back.
    $this->from('/laporkan?kode=Ab3xK9')
        ->post('/laporkan', ['report' => 'Zzz9Yx', 'reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->get('/laporkan?kode=Ab3xK9')->assertSee('value="Zzz9Yx"', escape: false);
});

it('makes the reporter choose a reason rather than defaulting to phishing', function () {
    // The first <option> is selected unless something else is, so without a
    // placeholder anybody who never opens the dropdown files a PHISHING report — the
    // field the operator triages on and the one in the mail subject. `required`
    // cannot fire while a value is always present.
    $html = (string) $this->get('/laporkan')->getContent();

    preg_match('~<select[^>]*name="reason".*?</select>~s', $html, $select);
    preg_match('~<option[^>]*>~', $select[0] ?? '', $first);

    expect($first[0] ?? '')->toContain('value=""')
        ->and($first[0] ?? '')->toContain('selected');
});

it('does not name the honeypot something a password manager fills', function () {
    // autocomplete="off" does not stop 1Password or Bitwarden. A manager filling the
    // trap drops a real report on the floor while showing the reporter the success
    // page — the one failure mode with no trace on either side.
    $html = (string) $this->get('/laporkan')->getContent();

    preg_match_all('~<input[^>]*name="([^"]+)"~', $html, $names);

    expect($names[1])->not->toContain('website')
        ->and($names[1])->not->toContain('url')
        ->and($names[1])->not->toContain('email')
        ->and($names[1])->not->toContain('name');
});

it('does not credit a foreign host with our slug', function (string $input) {
    QrCode::factory()->create(['slug' => 'Ab3xK9']);

    submit(['report' => $input]);

    // `https://attacker.example/x/Ab3xK9` is not a report of OUR Ab3xK9. Reading it
    // as one lets anybody raise a flag against any live code by inventing a URL that
    // never existed, and puts that code's destination in an operator's inbox. It is
    // still kept — as the free text it actually is.
    expect(AbuseFlag::sole())
        ->qr_code_id->toBeNull()
        ->url->toBe(trim($input));
})->with([
    'foreign host' => 'https://attacker.example/x/Ab3xK9',
    // The cheapest variant, and the one the first fix missed: with no scheme,
    // parse_url reports NO host and puts the authority in the path.
    'foreign host, no scheme' => 'attacker.example/x/Ab3xK9',
    'userinfo disguise' => 'https://kodeqr.com@attacker.example/x/Ab3xK9',
    'lookalike host' => 'https://kodeqr.com.attacker.example/x/Ab3xK9',
]);

it('keeps a report whose text is not a slug at all', function () {
    // Somebody pasting the destination they landed on, rather than the /x link, is
    // still telling us something. Throwing it away to keep the table tidy would lose
    // the reports from the least technical people, who are the usual victims.
    submit(['report' => 'https://penipuan.example/transfer-sekarang']);

    expect(AbuseFlag::sole())
        ->qr_code_id->toBeNull()
        ->url->toBe('https://penipuan.example/transfer-sekarang');
});

it('finds a soft-deleted code, because a deleted scam is still a scam', function () {
    $code = QrCode::factory()->create();
    $code->delete();

    submit(['report' => $code->slug]);

    expect(AbuseFlag::sole()->qr_code_id)->toBe($code->id);
});

it('swallows a honeypot submission without a word about it', function () {
    submit(['subjek' => 'https://spam.example'])
        ->assertRedirect(route('abuse.report.show'))
        ->assertSessionHas('status', 'reported')
        ->assertSessionHasNoErrors();

    // Same success the human gets. A 422 naming the field tells the author which one
    // gave them away, and the next run omits it.
    expect(AbuseFlag::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('stays silent for a honeypot of any shape', function (mixed $honeypot) {
    // The field is deliberately absent from the validation rules, so whatever a bot
    // sends arrives raw. `website[]=x` used to raise "Array to string conversion" —
    // a 500 from the one path whose entire value is that it says nothing at all.
    submit(['subjek' => $honeypot])
        ->assertRedirect(route('abuse.report.show'))
        ->assertSessionHas('status', 'reported');

    expect(AbuseFlag::count())->toBe(0);
})->with([
    'string' => 'https://spam.example',
    'array' => [['x']],
    'nested array' => [[['x' => 'y']]],
    'numeric' => '0',
]);

it('treats an empty honeypot as the human it probably is', function (mixed $honeypot) {
    // A browser submits the field empty. Reading that as a bot would silently discard
    // every real report, and the reporter would be told it worked.
    submit(['subjek' => $honeypot])->assertSessionHas('status', 'reported');

    expect(AbuseFlag::count())->toBe(1);
})->with([
    'empty string' => '',
    'whitespace' => '   ',
    'empty array' => [[]],
]);

it('requires the two things a report cannot be read without', function (array $payload, string $field) {
    submit($payload)->assertSessionHasErrors($field);

    expect(AbuseFlag::count())->toBe(0);
})->with([
    'no report' => [['report' => ''], 'report'],
    'no reason' => [['reason' => ''], 'reason'],
    'unknown reason' => [['reason' => 'bosan'], 'reason'],
    'malformed email' => [['reporter_email' => 'bukan-email'], 'reporter_email'],
    'oversized report' => [['report' => str_repeat('a', 2049)], 'report'],
]);

it('never asks the database whether the slug exists before validating', function () {
    // A rule that could fail only for an unknown slug would put the oracle back,
    // this time in the 422. Validation is about shape and nothing else.
    submit(['report' => 'Zzz9Yx'])->assertSessionHasNoErrors();
});

it('stops after five reports a minute from one address', function () {
    for ($i = 0; $i < 5; $i++) {
        submit()->assertRedirect(route('abuse.report.show'));
    }

    submit()->assertTooManyRequests();

    expect(AbuseFlag::count())->toBe(5);
});

it('does not spend the write budget on people reading the form', function () {
    // The throttle is on the POST only: sharing one bucket would mean a mistyped
    // submission costs the reporter the reload they need in order to fix it.
    for ($i = 0; $i < 10; $i++) {
        $this->get('/laporkan')->assertOk();
    }
});

it('renders the throttle refusal in Bahasa, on our own page', function () {
    // Constraint 10. The person seeing this has just lost a typed abuse report —
    // the worst possible moment to hand them an English framework page.
    for ($i = 0; $i < 5; $i++) {
        submit();
    }

    submit()->assertTooManyRequests()
        ->assertSee(__('errors.429.title'))
        ->assertDontSee('Too Many Requests');
});
