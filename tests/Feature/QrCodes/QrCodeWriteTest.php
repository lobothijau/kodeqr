<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Models\QrCode;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Faked at the DoH boundary rather than by swapping ThreatCheck out.
 *
 * ThreatCheck is final, but that is not the real reason: stubbing the service would
 * assert that a rule was attached while leaving the thing constraint 5 actually
 * depends on — how a censored answer is read — unexercised. This drives the real code.
 *
 * One stub, held in a static, because Http::fake() MERGES and the earliest match
 * wins: calling it again mid-test to flip safe -> malicious silently kept the first
 * answer, which made the edit-path test pass while proving nothing.
 */
function verdict(?bool $safe = null): bool
{
    static $state = true;

    if ($safe !== null) {
        $state = $safe;
    }

    return $state;
}

beforeEach(function () {
    verdict(safe: true);

    Http::fake(fn () => Http::response(verdict()
        ? ['Status' => 0, 'Answer' => [['name' => 'warung.test', 'type' => 1, 'TTL' => 60, 'data' => '203.0.113.10']]]
        // Cloudflare's security resolver answers a blocked domain with NXDOMAIN and
        // an Extended DNS Error 16 (censored).
        : ['Status' => 3, 'Comment' => ['EDE(16): Censored'], 'Authority' => []],
    ));
});

function planned(Plan $plan = Plan::Regular): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['plan' => $plan]);

    return $user;
}

it('creates a url code', function () {
    verdict(safe: true);
    $user = planned();

    $this->actingAs($user)->post(route('qr-codes.store'), [
        'type' => 'url', 'url' => 'https://warung.test/menu',
    ])->assertRedirect(route('qr-codes.index'));

    expect(QrCode::sole())
        ->user_id->toBe($user->id)
        ->type->toBe(QrCodeType::Url)
        ->status->toBe(QrCodeStatus::Active)
        ->and(QrCode::sole()->destination['dest_url'])->toBe('https://warung.test/menu')
        ->and(QrCode::sole()->slug)->toHaveLength(6);
});

it('creates a whatsapp code with the phone normalised', function () {
    verdict(safe: true);

    $this->actingAs(planned())->post(route('qr-codes.store'), [
        'type' => 'whatsapp', 'phone' => '081234567890', 'text' => 'Halo, mau pesan',
    ])->assertRedirect(route('qr-codes.index'));

    expect(QrCode::sole()->destination['dest_url'])->toBe('https://wa.me/6281234567890?text=Halo%2C%20mau%20pesan');
});

it('refuses a malicious destination on CREATE', function () {
    verdict(safe: false);

    $this->actingAs(planned())->post(route('qr-codes.store'), [
        'type' => 'url', 'url' => 'https://penipuan.example/transfer',
    ])->assertSessionHasErrors('url');

    expect(QrCode::count())->toBe(0);
});

it('refuses a malicious destination on EDIT', function () {
    // THE hole constraint 5 exists to close, and the reason M1-T5's rule was written
    // before there was anywhere to attach it: save something clean, let it be
    // reviewed, then edit it to a phishing page.
    verdict(safe: true);
    $user = planned();
    $this->actingAs($user)->post(route('qr-codes.store'), ['type' => 'url', 'url' => 'https://warung.test/menu']);
    $code = QrCode::sole();

    verdict(safe: false);
    $this->actingAs($user)->patch(route('qr-codes.update', $code), [
        'type' => 'url', 'url' => 'https://penipuan.example/transfer',
    ])->assertSessionHasErrors('url');

    expect($code->fresh()->destination['dest_url'])->toBe('https://warung.test/menu');
});

it('stops the fourth create on a free plan without crashing', function () {
    verdict(safe: true);
    $user = User::factory()->create();
    QrCode::factory()->for($user)->count(3)->create();

    $this->actingAs($user)->post(route('qr-codes.store'), [
        'type' => 'url', 'url' => 'https://warung.test/menu',
    ])
        ->assertRedirect(route('qr-codes.index'))
        ->assertSessionHas('quotaReached', fn (array $payload): bool =>
            // Asserting only the status would have accepted a bare 403 — which is what
            // this did before review, and what the task file's "upgrade-prompt error"
            // explicitly is not. The tier offered must be the NEXT one, never the
            // current one.
            str_contains($payload['message'], '3')
            && $payload['upgrade_to'] === Plan::Regular->value);

    expect(QrCode::where('user_id', $user->id)->count())->toBe(3);
});

it('offers nothing more to sell on the top tier', function () {
    $user = planned(Plan::Business);

    // Business has no upgrade target. Inventing one would put a tier that does not
    // exist in front of the customer paying the most.
    expect($user->entitlements()->plan()->upgradeTarget())->toBeNull();
});

it('refuses over quota before spending a threat lookup on it', function () {
    // Authorization runs before validation, so the refusal costs nothing. With the
    // check in the controller, anyone who could POST this form could make us perform
    // a DNS round-trip to a threat resolver on a URL of their choosing.
    $user = User::factory()->create();
    QrCode::factory()->for($user)->count(3)->create();

    $this->actingAs($user)->post(route('qr-codes.store'), [
        'type' => 'url', 'url' => 'https://warung.test/menu',
    ])->assertRedirect(route('qr-codes.index'));

    Http::assertNothingSent();
});

it('edits the destination and the next scan follows it', function () {
    verdict(safe: true);
    $user = planned();
    $this->actingAs($user)->post(route('qr-codes.store'), ['type' => 'url', 'url' => 'https://warung.test/lama']);
    $code = QrCode::sole();

    $this->get("/x/{$code->slug}")->assertRedirect('https://warung.test/lama');

    $this->actingAs($user)->patch(route('qr-codes.update', $code), [
        'type' => 'url', 'url' => 'https://warung.test/baru',
    ]);

    // The entire product promise, asserted end to end: the observer forgot the cache
    // on save, so the next scan of the same printed paper lands somewhere new.
    $this->get("/x/{$code->slug}")->assertRedirect('https://warung.test/baru');
});

it('pauses and resumes, and the scanner sees it immediately', function () {
    $user = planned();
    $code = QrCode::factory()->for($user)->create(['destination' => ['url' => 'https://warung.test/menu']]);
    $this->get("/x/{$code->slug}")->assertRedirect('https://warung.test/menu');

    $this->actingAs($user)->post(route('qr-codes.pause', $code));

    expect($code->fresh()->status)->toBe(QrCodeStatus::Paused);
    $this->get("/x/{$code->slug}")->assertGone()->assertSee(__('redirect.inactive.title'));

    $this->actingAs($user)->post(route('qr-codes.pause', $code));
    $this->get("/x/{$code->slug}")->assertRedirect('https://warung.test/menu');
});

it('will not let an owner un-block a blocked code from the builder', function (QrCodeStatus $status) {
    $user = planned();
    $code = QrCode::factory()->for($user)->create();
    $code->status = $status;
    $code->save();

    $this->actingAs($user)->post(route('qr-codes.pause', $code));

    // One is an abuse decision and the other a billing one. Neither is the owner's to
    // reverse with a toggle (constraint 8).
    expect($code->fresh()->status)->toBe($status);
})->with([
    'blocked' => [QrCodeStatus::Blocked],
    'over quota' => [QrCodeStatus::OverQuota],
]);

it('soft deletes, so printed paper still gets a branded page', function () {
    $user = planned();
    $code = QrCode::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('qr-codes.destroy', $code));

    expect($code->fresh()->trashed())->toBeTrue();
    $this->get("/x/{$code->slug}")->assertNotFound()->assertSee(__('redirect.not_found.title'));
});

it('answers a validation failure in Bahasa, never a raw key', function () {
    // The literal sentence, not `__('validation.url')`. Comparing against the
    // translator meant a MISSING key produced 'validation.url' on both sides and the
    // test passed on precisely the failure it claims to catch.
    $this->actingAs(planned())->post(route('qr-codes.store'), ['type' => 'url', 'url' => 'bukan-tautan'])
        ->assertSessionHasErrors([
            'url' => 'tautan tujuan harus berupa tautan yang valid, diawali http:// atau https://.',
        ]);
});

it('tells a lapsed owner what actually happened to them', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->lapsed()->create(['plan' => Plan::Regular]);

    $this->actingAs($user)->post(route('qr-codes.store'), ['type' => 'url', 'url' => 'https://warung.test/menu'])
        ->assertSessionHas('quotaReached', fn (array $payload): bool =>
            // "You have used all 0 of your codes" is nonsense addressed to somebody we
            // want back — their codes still redirect, they just cannot edit or create.
            $payload['message'] === __('qr.lapsed')
            && $payload['upgrade_to'] === Plan::Regular->value);
});

it('answers with a message, not a 500, when the renderer refuses the input', function (array $payload, string $field) {
    // These pass the cheap rules and then throw inside the model observer. Restating
    // the renderer's rules in a regex here would drift the first time it tightened,
    // so validation asks the renderer itself.
    $this->actingAs(planned())->post(route('qr-codes.store'), $payload)
        ->assertSessionHasErrors($field);

    expect(QrCode::count())->toBe(0);
})->with([
    'letters for a phone' => [['type' => 'whatsapp', 'phone' => 'abcdefgh'], 'phone'],
    'phone too short' => [['type' => 'whatsapp', 'phone' => '0812'], 'phone'],
    'userinfo disguise' => [['type' => 'url', 'url' => 'https://warung.test@evil.test/'], 'url'],
    'points back at a scan url' => [['type' => 'url', 'url' => 'https://localhost:8000/x/Ab3xK9'], 'url'],
]);
