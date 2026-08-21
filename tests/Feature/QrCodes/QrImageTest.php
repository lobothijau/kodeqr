<?php

declare(strict_types=1);

use App\Enums\QrCodeStatus;
use App\Enums\QrFormat;
use App\Models\QrCode;
use App\Models\User;
use App\Services\QrRenderer;
use App\Services\RenderSpec;
use Illuminate\Support\Facades\Cache;

it('renders a png for the owner', function (): void {
    $qr = QrCode::factory()->create();

    $response = $this->actingAs($qr->user)->get(route('qr-codes.image', $qr));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(substr((string) $response->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

/*
 * The IDOR sweep M2's gate calls for, on the endpoint added after that gate was
 * written. A picture of somebody else's code leaks its slug, which is the address
 * of their printed paper.
 */
it('refuses another account', function (): void {
    $qr = QrCode::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('qr-codes.image', $qr))
        ->assertForbidden();
});

it('requires authentication', function (): void {
    $qr = QrCode::factory()->create();

    $this->get(route('qr-codes.image', $qr))->assertRedirect(route('login'));
});

/*
 * The preview must not become an unmetered export. M2-T4 sells vector output and
 * print resolution; a route that answered `?size=2048&format=svg` would give both
 * away while the export endpoint was still checking entitlements.
 */
it('caps the size well below print resolution and never serves vector', function (): void {
    $qr = QrCode::factory()->create();

    $response = $this->actingAs($qr->user)
        ->get(route('qr-codes.image', ['qrCode' => $qr, 'size' => 4096]));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');

    $image = new Imagick;
    $image->readImageBlob($response->getContent());

    expect($image->getImageWidth())->toBe(512);
});

it('serves a paused or blocked code so the owner can still see what they own', function (QrCodeStatus $status): void {
    $qr = QrCode::factory()->status($status)->create();

    $this->actingAs($qr->user)->get(route('qr-codes.image', $qr))->assertOk();
})->with([QrCodeStatus::Paused, QrCodeStatus::Blocked]);

it('revalidates with an etag that changes when the style does', function (): void {
    $qr = QrCode::factory()->create(['style' => ['pattern' => 'dots']]);

    $first = $this->actingAs($qr->user)->get(route('qr-codes.image', $qr));
    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->actingAs($qr->user)
        ->withHeaders(['If-None-Match' => $etag])
        ->get(route('qr-codes.image', $qr))
        ->assertStatus(304);

    $qr->update(['style' => ['pattern' => 'square']]);

    $this->actingAs($qr->user)
        ->withHeaders(['If-None-Match' => $etag])
        ->get(route('qr-codes.image', $qr))
        ->assertOk();
});

/*
 * A style column written by an older release must not turn the owner's list into a
 * page of broken images.
 */
it('renders despite an unreadable stored style', function (): void {
    $qr = QrCode::factory()->create([
        'style' => ['pattern' => 'blob', 'foreground' => 'rebeccapurple'],
    ]);

    $this->actingAs($qr->user)->get(route('qr-codes.image', $qr))->assertOk();
});

/*
 * Both reviewers, independently. A stored style can be well-formed and still fail
 * the contrast gate — nothing rejects colours on write until M2-T3's builder does —
 * and the throw turned one bad saved colour into a 500 on EVERY card in the list,
 * plus a logged exception on every page load. The sibling test above only covered
 * MALFORMED values, which fromStyle() sanitises, so it sailed past this entirely.
 */
it('falls back to default ink rather than 500ing on an unscannable stored style', function (): void {
    $qr = QrCode::factory()->create([
        'style' => ['foreground' => '#fefefe', 'background' => '#ffffff'],
    ]);

    $response = $this->actingAs($qr->user)->get(route('qr-codes.image', $qr));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');

    // Not merely "a 200": the bytes must be the default-ink render, or the fallback
    // is drawing something nobody can scan.
    $expected = app(QrRenderer::class)->render(
        $qr,
        new RenderSpec(format: QrFormat::Png, size: 320),
    );

    expect($response->getContent())->toBe($expected);
});

/*
 * ~50ms of Imagick per request, one request per card, on the same PHP worker pool
 * that serves /x/{slug} — which constraint 1 says must never hand a scanner a 5xx.
 * The render must happen once per fingerprint, not once per request.
 */
it('renders once and serves the rest from cache', function (): void {
    Cache::flush();
    $qr = QrCode::factory()->create();

    $warm = $this->actingAs($qr->user)->get(route('qr-codes.image', $qr));
    $warm->assertOk();

    /*
     * QrRenderer is final, so it cannot be mocked to assert "not called". Poisoning
     * the cache entry proves the same thing from the other side: if the second
     * request re-renders, it returns real PNG bytes and this fails. The entry is
     * keyed on the same digest the ETag carries, which is what makes it findable
     * from out here.
     */
    $key = 'qr-preview:'.trim((string) $warm->headers->get('ETag'), '"');
    expect(Cache::has($key))->toBeTrue();
    Cache::put($key, 'DARI-CACHE', 60);

    $second = $this->actingAs($qr->user)
        // A fresh client sends no validator, so this exercises the render cache
        // rather than the 304 path.
        ->get(route('qr-codes.image', $qr));

    $second->assertOk();
    expect($second->getContent())->toBe('DARI-CACHE');
});

it('busts the cache when the style changes', function (): void {
    Cache::flush();
    $qr = QrCode::factory()->create(['style' => ['pattern' => 'square']]);

    $first = $this->actingAs($qr->user)->get(route('qr-codes.image', $qr))->getContent();

    $qr->update(['style' => ['pattern' => 'dots']]);

    $second = $this->actingAs($qr->user)->get(route('qr-codes.image', $qr))->getContent();

    expect($second)->not->toBe($first);
});

/*
 * RFC 7232 requires WEAK comparison for GET and allows a comma-separated list. The
 * hand-rolled `===` matched neither, so a client or CDN sending `W/"..."` would
 * never get a 304 — the render cost would return in production while this suite
 * stayed green, because the old test replayed the exact header back.
 */
it('honours a weak validator and a validator list', function (string $header): void {
    $qr = QrCode::factory()->create();

    $etag = trim((string) $this->actingAs($qr->user)
        ->get(route('qr-codes.image', $qr))
        ->headers->get('ETag'), '"');

    $this->actingAs($qr->user)
        ->withHeaders(['If-None-Match' => str_replace('%s', $etag, $header)])
        ->get(route('qr-codes.image', $qr))
        ->assertStatus(304);
})->with([
    'strong' => ['"%s"'],
    'weak' => ['W/"%s"'],
    'list' => ['"deadbeef", "%s"'],
    'wildcard' => ['*'],
]);

it('sends validators on the 304 as well as the 200', function (): void {
    $qr = QrCode::factory()->create();

    $etag = (string) $this->actingAs($qr->user)
        ->get(route('qr-codes.image', $qr))
        ->headers->get('ETag');

    $notModified = $this->actingAs($qr->user)
        ->withHeaders(['If-None-Match' => $etag])
        ->get(route('qr-codes.image', $qr));

    $notModified->assertStatus(304)
        ->assertHeader('ETag', $etag)
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');
});
