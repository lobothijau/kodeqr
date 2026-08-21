<?php

declare(strict_types=1);

use App\Enums\AbuseSource;
use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Http\Controllers\RedirectController;
use App\Models\AbuseFlag;
use App\Models\QrCode;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * A paid owner, so a working code answers with a 302 rather than the free-tier
 * splash — the block has to be visible in the status line, not buried in a page.
 */
function paidCode(string $url = 'https://warung.test/menu'): QrCode
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['plan' => Plan::Regular]);

    return QrCode::factory()->for($user)->create(['destination' => ['url' => $url]]);
}

it('blocks a code and the very next scan sees it', function () {
    $code = paidCode('https://penipuan.example/transfer');

    // Warm the cache first — this is the whole risk. A block that only takes effect
    // when the entry expires leaves a live scam redirecting for up to six hours.
    $this->get("/x/{$code->slug}")->assertRedirect('https://penipuan.example/transfer');
    expect(Cache::has(RedirectController::cacheKey($code->slug)))->toBeTrue();

    $this->artisan('qr:block', ['slug' => $code->slug])->assertSuccessful();

    expect(Cache::has(RedirectController::cacheKey($code->slug)))->toBeFalse()
        ->and($code->fresh()->status)->toBe(QrCodeStatus::Blocked);

    $this->get("/x/{$code->slug}")
        ->assertGone()
        ->assertSee(__('redirect.blocked.title'));
});

it('writes an audit row for every block', function () {
    $code = QrCode::factory()->create(['destination' => ['url' => 'https://penipuan.example/x']]);

    $this->artisan('qr:block', ['slug' => $code->slug]);

    $flag = AbuseFlag::sole();

    // A status with no recorded reason is a status the next operator cannot safely
    // undo — they have no way to know whether it was a scam or a fat finger.
    expect($flag->source)->toBe(AbuseSource::Admin)
        ->and($flag->qr_code_id)->toBe($code->id)
        ->and($flag->url)->toBe('https://penipuan.example/x');
});

it('unblocks and the next scan redirects again', function () {
    $code = paidCode();
    $this->artisan('qr:block', ['slug' => $code->slug]);
    $this->get("/x/{$code->slug}")->assertGone();

    $this->artisan('qr:block', ['slug' => $code->slug, '--unblock' => true])->assertSuccessful();

    expect($code->fresh()->status)->toBe(QrCodeStatus::Active);
    $this->get("/x/{$code->slug}")->assertRedirect('https://warung.test/menu');
});

it('leaves no audit row when unblocking', function () {
    $code = QrCode::factory()->create();
    $this->artisan('qr:block', ['slug' => $code->slug]);

    $this->artisan('qr:block', ['slug' => $code->slug, '--unblock' => true]);

    expect(AbuseFlag::count())->toBe(1);
});

it('refuses to unblock something that was never blocked', function (QrCodeStatus $status) {
    $code = QrCode::factory()->create();
    $code->status = $status;
    $code->save();

    $this->artisan('qr:block', ['slug' => $code->slug, '--unblock' => true])->assertSuccessful();

    // Unblocking a paused code would silently republish something the OWNER turned
    // off — a different decision, made by a different person, for different reasons.
    expect($code->fresh()->status)->toBe($status);
})->with([
    'paused' => [QrCodeStatus::Paused],
    'over quota' => [QrCodeStatus::OverQuota],
]);

it('does not let a second block wedge the kill switch on', function () {
    $code = paidCode();

    // Two operators reacting to one report, or one operator retrying, is the ordinary
    // case. A second audit row would record previous_status = blocked, and --unblock
    // would then "restore" the code to blocked while printing that it was released.
    $this->artisan('qr:block', ['slug' => $code->slug])->assertSuccessful();
    $this->artisan('qr:block', ['slug' => $code->slug])
        ->expectsOutputToContain('already blocked')
        ->assertSuccessful();

    expect(AbuseFlag::count())->toBe(1);

    $this->artisan('qr:block', ['slug' => $code->slug, '--unblock' => true]);

    expect($code->fresh()->status)->toBe(QrCodeStatus::Active);
    $this->get("/x/{$code->slug}")->assertRedirect('https://warung.test/menu');
});

it('tells the operator the status it actually landed on', function () {
    $code = paidCode();
    $code->status = QrCodeStatus::Paused;
    $code->save();
    $this->artisan('qr:block', ['slug' => $code->slug]);

    // "Restored to active" was printed unconditionally, so an operator restoring a
    // paused code was told scanning worked again while /x/ went on answering 410.
    $this->artisan('qr:block', ['slug' => $code->slug, '--unblock' => true])
        ->expectsOutputToContain('restored to paused');
});

it('fails loudly on an unknown slug', function () {
    $this->artisan('qr:block', ['slug' => 'Zzz9Yx'])->assertFailed();

    expect(AbuseFlag::count())->toBe(0);
});

it('gives a paused code back to its owner still paused', function () {
    $code = paidCode();
    $code->status = QrCodeStatus::Paused;
    $code->save();

    $this->artisan('qr:block', ['slug' => $code->slug]);
    $this->artisan('qr:block', ['slug' => $code->slug, '--unblock' => true]);

    // Restoring `active` here would silently reverse the OWNER's decision to take
    // the code down — a different decision, by a different person, for different
    // reasons. Nothing re-derives `paused`; it exists only because somebody chose it.
    expect($code->fresh()->status)->toBe(QrCodeStatus::Paused);
    $this->get("/x/{$code->slug}")->assertGone();
});

it('falls back to active when there is no record of what it was', function () {
    $code = paidCode();
    $code->status = QrCodeStatus::Blocked;
    $code->save();

    // A code blocked before this column existed, or whose flags were pruned.
    $this->artisan('qr:block', ['slug' => $code->slug, '--unblock' => true])->assertSuccessful();

    expect($code->fresh()->status)->toBe(QrCodeStatus::Active);
});

it('blocks a code the owner had merely paused', function () {
    $code = paidCode();
    $code->status = QrCodeStatus::Paused;
    $code->save();

    $this->artisan('qr:block', ['slug' => $code->slug])->assertSuccessful();

    // The dangerous direction always wins: a paused scam is one un-pause away from
    // being live again, and the owner controls that button.
    expect($code->fresh()->status)->toBe(QrCodeStatus::Blocked);
});
