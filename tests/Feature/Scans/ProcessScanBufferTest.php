<?php

declare(strict_types=1);

use App\Enums\ScanDevice;
use App\Http\Controllers\RedirectController;
use App\Jobs\ProcessScanBuffer;
use App\Models\QrCode;
use App\Models\ScanEvent;
use App\Services\ScanEnricher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 13; SM-A536E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

function redisUp(): bool
{
    static $up = null;

    if ($up === null) {
        try {
            Redis::connection()->ping();
            $up = true;
        } catch (Throwable) {
            $up = false;
        }
    }

    return $up;
}

function skipWithoutRedisServer(): bool
{
    return ! redisUp();
}

/**
 * Hashed the way RedirectController hashes: the WIB date is part of the salt, so the
 * same scanner is a different hash tomorrow.
 */
function ipHash(string $scanner): string
{
    $day = now()->timezone((string) config('app.display_timezone'))->format('Y-m-d');

    return hash('sha256', $day.config('app.key').$scanner);
}

/**
 * @return array<string, mixed>
 */
function scanPayload(QrCode $code, array $overrides = []): array
{
    return [
        'uuid' => (string) Str::ulid(),
        'slug' => $code->slug,
        'qr_id' => $code->id,
        't' => now()->timestamp,
        'ip_hash' => ipHash('scanner-one'),
        'ua' => ANDROID_UA,
        'ref' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $payloads
 */
function buffer(array $payloads): void
{
    Redis::connection()->rpush(
        RedirectController::BUFFER_KEY,
        ...array_map(fn (array $payload): string => json_encode($payload), $payloads),
    );
}

function bufferLength(): int
{
    return (int) Redis::connection()->llen(RedirectController::BUFFER_KEY);
}

function drain(): void
{
    app(ProcessScanBuffer::class)->handle(app(ScanEnricher::class));
}

beforeEach(function () {
    if (redisUp()) {
        Redis::connection()->flushdb();
    }
});

it('drains 1,200 payloads into 1,200 rows using one insert per chunk', function () {
    $code = QrCode::factory()->create();
    $payloads = [];

    for ($i = 0; $i < 1_200; $i++) {
        $payloads[] = scanPayload($code, ['ip_hash' => ipHash("scanner-{$i}")]);
    }

    buffer($payloads);

    DB::enableQueryLog();
    DB::flushQueryLog();

    drain();

    $inserts = array_filter(
        DB::getQueryLog(),
        fn (array $query): bool => str_starts_with(strtolower((string) $query['query']), 'insert')
            && str_contains(strtolower((string) $query['query']), 'scan_events'),
    );

    // 500 + 500 + 200. One statement per chunk, not one per event: at a thousand
    // scans a minute the difference is the whole design.
    expect(ScanEvent::query()->count())->toBe(1_200)
        ->and($inserts)->toHaveCount(3)
        ->and(bufferLength())->toBe(0)
        ->and($code->fresh()->scan_count)->toBe(1_200)
        ->and($code->fresh()->last_scanned_at)->not->toBeNull();
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('produces the same rows and the same counters when a chunk is replayed', function () {
    $code = QrCode::factory()->create();
    $payloads = array_map(
        fn (int $i): array => scanPayload($code, ['ip_hash' => ipHash("scanner-{$i}")]),
        range(1, 200),
    );

    buffer($payloads);
    drain();

    buffer($payloads);
    drain();

    // Constraint 9. Counting rows rather than inserts is what would quietly corrupt
    // a plan's cap on every replay, long after the row count looked fine.
    expect(ScanEvent::query()->count())->toBe(200)
        ->and($code->fresh()->scan_count)->toBe(200);
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('counts a payload once when the same one lands twice in a single chunk', function () {
    $code = QrCode::factory()->create();
    $payload = scanPayload($code);

    // The redis connection retries a write whose reply timed out, so an rpush the
    // server actually ran can leave two copies. Both arrive in one LPOP, the insert
    // collapses them, and a counter that trusted the array length would not.
    buffer([$payload, $payload]);

    drain();

    expect(ScanEvent::query()->count())->toBe(1)
        ->and($code->fresh()->scan_count)->toBe(1);
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('still calls a first visit unique after the chunk carrying it failed once', function () {
    $code = QrCode::factory()->create();
    buffer([scanPayload($code, ['ip_hash' => ipHash('first-visit')])]);

    $database = app('db');
    DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('deadlock'));
    expect(fn () => drain())->toThrow(RuntimeException::class);

    // Hand the real manager back; the facade mock replaced the container binding too.
    DB::swap($database);
    drain();

    // The claim made by the failed attempt is released with the chunk. Left behind,
    // the replay would record the only real visit of the day as a repeat — and
    // scan_events is append-only, so that count would be wrong for ever.
    expect(ScanEvent::query()->sole()->is_unique)->toBeTrue();
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('keeps the chunk when mapping throws, not just when the insert does', function () {
    $code = QrCode::factory()->create();
    buffer(array_map(fn (int $i): array => scanPayload($code), range(1, 5)));

    $enricher = Mockery::mock(ScanEnricher::class);
    $enricher->shouldReceive('forgetClaims')->andReturnNull();
    $enricher->shouldReceive('releaseClaims')->andReturnNull();
    $enricher->shouldReceive('toRow')->andThrow(new RuntimeException('valkey died mid-parse'));

    // LPOP has already acknowledged these five. An exception between the pop and the
    // insert used to lose them silently.
    expect(fn () => app(ProcessScanBuffer::class)->handle($enricher))->toThrow(RuntimeException::class);

    expect(bufferLength())->toBe(5);
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('refuses a timestamp no scan could carry', function (mixed $timestamp) {
    $code = QrCode::factory()->create();
    buffer([scanPayload($code, ['t' => $timestamp])]);

    // 253402300800 formats to year 10000, past what MySQL DATETIME holds: INSERT
    // IGNORE would drop the row while the counter still counted it.
    drain();

    expect(ScanEvent::query()->count())->toBe(0)
        ->and($code->fresh()->scan_count)->toBe(0);
})->with([
    'year 10000' => [253402300800],
    'before the product existed' => [946684800],
    'not a number' => ['soon'],
])->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('parses the user agent into the columns a dashboard groups by', function () {
    $code = QrCode::factory()->create();
    buffer([scanPayload($code)]);

    drain();

    $event = ScanEvent::query()->sole();

    expect($event->device)->toBe(ScanDevice::Mobile)
        ->and($event->os)->toBe('Android')
        ->and($event->browser)->toBe('Chrome Mobile')
        ->and($event->is_bot)->toBeFalse();
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('flags link previews as bots and keeps them out of dashboard queries', function (string $userAgent) {
    $code = QrCode::factory()->create();
    buffer([scanPayload($code, ['ua' => $userAgent])]);

    drain();

    // Kept for debugging, excluded from every dashboard and aggregate. DeviceDetector
    // itself answers false for WhatsApp, which is why the substring list is the
    // authority here.
    expect(ScanEvent::query()->sole()->is_bot)->toBeTrue()
        ->and(ScanEvent::query()->where('is_bot', false)->count())->toBe(0)
        ->and($code->fresh()->scan_count)->toBe(1);
})->with([
    'whatsapp preview' => ['WhatsApp/2.23.20.0 A'],
    'facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
    'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
    'curl' => ['curl/8.4.0'],
    'headless chrome' => ['Mozilla/5.0 HeadlessChrome/120.0.0.0'],
])->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('counts a scanner once a day and again the next day', function () {
    $code = QrCode::factory()->create();
    buffer([
        scanPayload($code, ['ip_hash' => ipHash('same-scanner')]),
        scanPayload($code, ['ip_hash' => ipHash('same-scanner')]),
    ]);
    drain();

    expect(ScanEvent::query()->where('is_unique', true)->count())->toBe(1)
        ->and(ScanEvent::query()->where('is_unique', false)->count())->toBe(1);

    // Tomorrow the same person hashes differently — the date is in the salt — so the
    // claim key is a different key. The 24h TTL is belt to that braces: it reaps the
    // old keys rather than being what makes the day roll over.
    $this->travel(1)->day();
    buffer([scanPayload($code, ['ip_hash' => ipHash('same-scanner')])]);
    drain();

    expect(ScanEvent::query()->where('is_unique', true)->count())->toBe(2);
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('never lets a bot claim a scanner uniqueness slot', function () {
    $code = QrCode::factory()->create();
    buffer([scanPayload($code, ['ip_hash' => ipHash('shared'), 'ua' => 'WhatsApp/2.23.20.0 A'])]);
    drain();
    buffer([scanPayload($code, ['ip_hash' => ipHash('shared')])]);
    drain();

    // The preview arrives before the human taps the link. If it took the slot, the
    // only real visit of the day would be recorded as a repeat.
    expect(ScanEvent::query()->where('is_bot', true)->sole()->is_unique)->toBeFalse()
        ->and(ScanEvent::query()->where('is_bot', false)->sole()->is_unique)->toBeTrue();
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('keeps the buffer intact when the database goes away mid-run', function () {
    $code = QrCode::factory()->create();
    buffer(array_map(fn (int $i): array => scanPayload($code), range(1, 10)));

    // The write is made to fail at the call itself rather than by dropping the table:
    // on MySQL a DDL statement implicitly commits, which destroys the test's own
    // transaction before the job ever sees an error. What is under test here is this
    // job's behaviour when the write fails, not Laravel's rollback.
    DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('MySQL server has gone away'));

    expect(fn () => drain())->toThrow(RuntimeException::class);

    // Nothing is acknowledged until it is committed, so an outage costs latency, not
    // scans. The event ids make the replay safe.
    expect(bufferLength())->toBe(10)
        ->and(ScanEvent::query()->count())->toBe(0);
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('drops payloads it cannot read instead of poisoning the chunk', function () {
    $code = QrCode::factory()->create();
    Log::spy();

    buffer([
        scanPayload($code),
        scanPayload($code, ['uuid' => (string) Str::uuid()]),
        scanPayload($code, ['ip_hash' => 'not-a-hash']),
        scanPayload($code, ['t' => 'yesterday']),
        scanPayload($code, ['qr_id' => 'nope']),
    ]);
    Redis::connection()->rpush(RedirectController::BUFFER_KEY, 'this is not json');

    drain();

    // A 36-char uuid would be rejected by char(26) under strict mode and cost the
    // whole chunk. One unreadable payload must never cost the other 499.
    expect(ScanEvent::query()->count())->toBe(1)
        ->and(bufferLength())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('drops scans for a code that no longer exists rather than retrying forever', function () {
    $code = QrCode::factory()->create();
    $payload = scanPayload($code);
    $code->forceDelete();

    buffer([$payload]);
    Log::spy();

    drain();

    // A foreign key violation is the one error INSERT IGNORE swallows on MySQL and
    // does not on SQLite: left in, this payload would be requeued and rethrown every
    // minute for ever on one engine and vanish on the other.
    expect(ScanEvent::query()->count())->toBe(0)
        ->and(bufferLength())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('does not drag last_scanned_at backwards when chunks arrive out of order', function () {
    $code = QrCode::factory()->create();

    buffer([scanPayload($code, ['t' => now()->timestamp])]);
    drain();
    $latest = $code->fresh()->last_scanned_at;

    buffer([scanPayload($code, ['t' => now()->subHour()->timestamp])]);
    drain();

    expect($code->fresh()->last_scanned_at->timestamp)->toBe($latest->timestamp)
        ->and($code->fresh()->scan_count)->toBe(2);
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');

it('stops after ten thousand payloads and leaves the rest for the next minute', function () {
    $code = QrCode::factory()->create();

    for ($batch = 0; $batch < 21; $batch++) {
        buffer(array_map(
            fn (int $i): array => scanPayload($code, ['ip_hash' => ipHash("s-{$batch}-{$i}")]),
            range(1, 500),
        ));
    }

    drain();

    // A backlog is drained over several minutes rather than one run holding a worker
    // for an hour.
    expect(ScanEvent::query()->count())->toBe(ProcessScanBuffer::MAX_PER_RUN)
        ->and(bufferLength())->toBe(500);
})->skip(skipWithoutRedisServer(...), 'Redis not reachable.');
