<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Http\Controllers\RedirectController;
use App\Jobs\FlagQrCodeOverQuota;
use App\Models\QrCode;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answered once per run. Without a server these tests skip; the MySQL CI leg runs
 * with one and uses --fail-on-skipped, so they cannot quietly stop executing.
 */
function redisReachable(): bool
{
    static $reachable = null;

    if ($reachable === null) {
        try {
            Redis::connection()->ping();
            $reachable = true;
        } catch (Throwable) {
            $reachable = false;
        }
    }

    return $reachable;
}

function skipWithoutRedis(): bool
{
    return ! redisReachable();
}

/**
 * @return array<int, array<string, mixed>>
 */
function bufferedScans(): array
{
    $entries = Redis::connection()->lrange(RedirectController::BUFFER_KEY, 0, -1);

    return array_map(fn (string $json): array => json_decode($json, true), is_array($entries) ? $entries : []);
}

function scanCode(int $scanCount = 0, QrCodeStatus $status = QrCodeStatus::Active, ?Plan $plan = null): QrCode
{
    $user = User::factory()->create();

    if ($plan !== null) {
        Subscription::factory()->for($user)->create(['plan' => $plan]);
    }

    return QrCode::factory()->for($user)->create([
        'status' => $status,
        'scan_count' => $scanCount,
        'destination' => ['url' => 'https://example.test/menu'],
    ]);
}

beforeEach(function () {
    if (redisReachable()) {
        Redis::connection()->flushdb();
    }
});

it('buffers one payload per scan in the shape the processor expects', function () {
    $code = scanCode();

    $this->get("/x/{$code->slug}")->assertRedirect();

    $scans = bufferedScans();

    expect($scans)->toHaveCount(1)
        ->and(array_keys($scans[0]))->toBe(['uuid', 'slug', 'qr_id', 't', 'ip_hash', 'ua', 'ref'])
        // char(26) under MySQL strict mode: a 36-char Str::uuid() here would have
        // M1-T4's whole chunk rejected on insert.
        ->and($scans[0]['uuid'])->toHaveLength(26)
        ->and($scans[0]['slug'])->toBe($code->slug)
        ->and($scans[0]['qr_id'])->toBe($code->id)
        ->and($scans[0]['t'])->toBeGreaterThanOrEqual(now()->timestamp - 5);

    $this->get("/x/{$code->slug}")->assertRedirect();

    // Two scans, two entries, two distinct event ids — M1-T4 dedupes on that id, so
    // a repeated one would silently collapse real scans into one.
    expect(bufferedScans())->toHaveCount(2)
        ->and(bufferedScans()[1]['uuid'])->not->toBe($scans[0]['uuid']);
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('never lets a raw ip address into the buffer', function () {
    $code = scanCode();

    $this->get("/x/{$code->slug}", ['CF-Connecting-IP' => '203.0.113.77']);

    $raw = Redis::connection()->lrange(RedirectController::BUFFER_KEY, 0, -1)[0];

    // Constraint 3 and I4. The address exists in one local variable for the length
    // of one hash and is never written, queued or logged.
    expect($raw)->not->toContain('203.0.113.77')
        ->and(preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $raw))->toBe(0)
        ->and(bufferedScans()[0]['ip_hash'])->toMatch('/^[0-9a-f]{64}$/');
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('hashes the Cloudflare address rather than the socket address', function () {
    $code = scanCode();

    $this->get("/x/{$code->slug}", ['CF-Connecting-IP' => '203.0.113.77']);
    $this->get("/x/{$code->slug}", ['CF-Connecting-IP' => '198.51.100.4']);
    $this->get("/x/{$code->slug}");

    $hashes = array_column(bufferedScans(), 'ip_hash');

    // All three share a REMOTE_ADDR. Behind the orange cloud that address is
    // Cloudflare's, and hashing it would collapse a whole city into one scanner.
    expect($hashes[0])->not->toBe($hashes[1])
        ->and($hashes[2])->not->toBe($hashes[0])
        ->and($hashes[2])->toBe(hash('sha256', now()->format('Y-m-d').config('app.key').'127.0.0.1'));
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('rotates the ip hash daily so scanners cannot be joined across days', function () {
    $code = scanCode();

    $this->get("/x/{$code->slug}", ['CF-Connecting-IP' => '203.0.113.77']);
    $today = bufferedScans()[0]['ip_hash'];

    $this->travel(1)->day();
    $this->get("/x/{$code->slug}", ['CF-Connecting-IP' => '203.0.113.77']);

    expect(bufferedScans()[1]['ip_hash'])->not->toBe($today);
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('truncates a hostile user agent instead of dropping the scan', function () {
    $code = scanCode();

    $this->get("/x/{$code->slug}", [
        'User-Agent' => str_repeat('Mozilla ', 100),
        'Referer' => 'https://ref.test/'.str_repeat('a', 400),
    ]);

    $scan = bufferedScans()[0];

    // 255 characters, cut on a character boundary: half a multibyte character is
    // invalid UTF-8, and json_encode answers that with false rather than an
    // exception — a silently dropped scan.
    expect(mb_strlen((string) $scan['ua']))->toBe(255)
        ->and(mb_strlen((string) $scan['ref']))->toBe(255);
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('records nothing for a code that is not redirecting', function (QrCodeStatus $status) {
    $code = scanCode(status: $status);

    $this->get("/x/{$code->slug}")->assertStatus(Response::HTTP_GONE);

    expect(bufferedScans())->toBe([]);
})->with([
    'paused' => [QrCodeStatus::Paused],
    'blocked' => [QrCodeStatus::Blocked],
])->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('seeds the cap counter from the database when the cache is cold', function () {
    $code = scanCode(scanCount: 42);

    $this->get("/x/{$code->slug}")->assertRedirect();

    // Without the seed a rebuilt cache entry hands a code that already used 480 of
    // its 500 scans another 500.
    expect((int) Redis::connection()->get("scans:count:{$code->id}"))->toBe(43);
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('lets the last scan inside the cap through', function () {
    $code = scanCode(scanCount: 499);

    $this->get("/x/{$code->slug}")->assertRedirect('https://example.test/menu');
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('serves the over quota page on the scan past the cap and flips the row once', function () {
    Bus::fake();
    $code = scanCode(scanCount: 500);

    $this->get("/x/{$code->slug}")
        ->assertStatus(Response::HTTP_GONE)
        ->assertHeaderMissing('Location')
        ->assertSee(__('redirect.inactive.title'));

    // INCR is atomic, so exactly one request sees the crossing however many arrive
    // at once — a flood must not queue one job per scan.
    Bus::assertDispatchedTimes(FlagQrCodeOverQuota::class, 1);

    $this->get("/x/{$code->slug}")->assertStatus(Response::HTTP_GONE);

    Bus::assertDispatchedTimes(FlagQrCodeOverQuota::class, 1);
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('makes the row agree with the page within one queue cycle', function () {
    $code = scanCode(scanCount: 500);

    $this->get("/x/{$code->slug}")->assertStatus(Response::HTTP_GONE);

    // The queue is sync here, so this is the job having run. The dashboard and the
    // scanner must not disagree about why a code stopped redirecting.
    expect($code->fresh()->status)->toBe(QrCodeStatus::OverQuota);
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('never caps a paid code', function () {
    $code = scanCode(scanCount: 10_000, plan: Plan::Business);

    $this->get("/x/{$code->slug}")->assertRedirect('https://example.test/menu');
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('redirects and logs once when the buffer is unreachable', function () {
    $code = scanCode();

    // Warm the entry first: a cold miss also seeds the counter, and this asserts the
    // recording path's own logging, not the seed's.
    $this->get("/x/{$code->slug}")->assertRedirect();

    Log::spy();
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('valkey is down'));

    // Acceptance (a): the buffer is best-effort, the redirect is not (I2, I3).
    $this->get("/x/{$code->slug}")->assertRedirect('https://example.test/menu');

    Log::shouldHaveReceived('warning')->once();
});

it('fails open on the cap when the counter cannot be read', function () {
    $code = scanCode(scanCount: 10_000);

    // Down before the first scan, so neither the seed nor the counter answers. The
    // row says this free code is 9,500 scans past its cap and it still redirects.
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('valkey is down'));

    // A code the owner paid nothing for still beats a dead end at printed paper:
    // constraint 8 says the scanner always gets somewhere, and refusing one because
    // our own counter is down is exactly the failure it forbids.
    $this->get("/x/{$code->slug}")->assertRedirect('https://example.test/menu');
});

it('still enforces the cap when only the buffer write fails', function () {
    $code = scanCode(scanCount: 500);
    $connection = Mockery::mock();
    $connection->shouldReceive('incr')->once()->andReturn(501);
    $connection->shouldReceive('rpush')->once()->andThrow(new RuntimeException('OOM command not allowed'));
    $connection->shouldReceive('setnx')->andReturn(true);
    Redis::shouldReceive('connection')->andReturn($connection);
    Bus::fake();

    // Losing the count with the payload would let the crossing scan through, and
    // every later scan is already past cap + 1 — so the row would never flip at all.
    $this->get("/x/{$code->slug}")->assertStatus(Response::HTTP_GONE);

    Bus::assertDispatchedTimes(FlagQrCodeOverQuota::class, 1);
});

it('serves the over quota page even when the queue refuses the job', function () {
    $code = scanCode(scanCount: 500);
    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')->andThrow(new RuntimeException('queue is down'))
        ->shouldReceive('dispatchToQueue')->andThrow(new RuntimeException('queue is down'));

    // The dashboard row is the least important thing on this path: a dead queue must
    // not turn this scanner's correct 410 into the generic unavailable page.
    $this->get("/x/{$code->slug}")
        ->assertStatus(Response::HTTP_GONE)
        ->assertSee(__('redirect.inactive.title'));
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('ignores a forwarded-for header a direct-origin client could forge', function () {
    $code = scanCode();

    $this->get("/x/{$code->slug}", ['X-Forwarded-For' => '203.0.113.77']);

    // The only request that reaches the fallback is the one that bypassed the edge,
    // so its own headers are the last thing to believe. The socket peer is the
    // fallback, not $request->ip().
    expect(bufferedScans()[0]['ip_hash'])
        ->toBe(hash('sha256', now()->format('Y-m-d').config('app.key').'127.0.0.1'));
})->skip(skipWithoutRedis(...), 'Redis not reachable.');

it('does not flag a code whose owner upgraded before the job ran', function () {
    $code = scanCode(status: QrCodeStatus::Active, plan: Plan::Regular);

    (new FlagQrCodeOverQuota($code->id))->handle();

    // Flipping now would leave a paying customer's printed code showing "tidak
    // aktif" with nothing in the system to clear it (constraint 8).
    expect($code->fresh()->status)->toBe(QrCodeStatus::Active);
});

it('flips only an active code and drops its cache entry', function () {
    $code = scanCode();
    $this->get("/x/{$code->slug}")->assertRedirect();

    (new FlagQrCodeOverQuota($code->id))->handle();

    // The observer's forget is what makes the next scan see the new status rather
    // than the cached `active` for another six hours.
    expect($code->fresh()->status)->toBe(QrCodeStatus::OverQuota)
        ->and(Cache::get(RedirectController::cacheKey($code->slug)))->toBeNull();

    $this->get("/x/{$code->slug}")->assertStatus(Response::HTTP_GONE);
});

it('leaves a code that was blocked in the meantime alone', function () {
    $code = scanCode(status: QrCodeStatus::Blocked);

    (new FlagQrCodeOverQuota($code->id))->handle();

    // over_quota shows a milder page than the one an abuse report earned this code.
    expect($code->fresh()->status)->toBe(QrCodeStatus::Blocked);
});

it('does nothing for a code that no longer exists', function () {
    expect(fn () => (new FlagQrCodeOverQuota('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->handle())->not->toThrow(Throwable::class);
});
