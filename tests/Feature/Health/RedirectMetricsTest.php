<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Http\Controllers\RedirectController;
use App\Jobs\ProcessScanBuffer;
use App\Models\QrCode;
use App\Models\ScanEvent;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CanarySeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

function metricsDate(): string
{
    return now()->timezone('Asia/Jakarta')->toDateString();
}

beforeEach(function () {
    foreach (['buffered', 'processed', 'bots'] as $name) {
        Redis::connection()->del(ProcessScanBuffer::metricKey($name, metricsDate()));
    }

    Redis::connection()->del(RedirectController::BUFFER_KEY, 'health:latency:'.metricsDate());
})->skip(fn (): bool => ! extension_loaded('redis'), 'Requires Redis.');

it('counts what the pipeline actually moved', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['plan' => Plan::Regular]);
    $code = QrCode::factory()->for($user)->create();

    // A human and a bot, so bot_pct has something to divide.
    $this->get("/x/{$code->slug}", ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15']);
    $this->get("/x/{$code->slug}", ['User-Agent' => 'curl/8.7.1']);

    dispatch_sync(new ProcessScanBuffer);

    $redis = Redis::connection();

    expect((int) $redis->get(ProcessScanBuffer::metricKey('buffered', metricsDate())))->toBe(2)
        ->and((int) $redis->get(ProcessScanBuffer::metricKey('processed', metricsDate())))->toBe(2)
        ->and((int) $redis->get(ProcessScanBuffer::metricKey('bots', metricsDate())))->toBe(1);
});

it('logs one line a human can read', function () {
    Log::spy();
    Redis::connection()->rpush('health:latency:'.metricsDate(), ...range(1, 100));

    $this->artisan('redirect:metrics', ['--date' => metricsDate()])->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $line): bool {
        // Nearest-rank, so the p95 of 1..100 is a latency that was really measured.
        return $message === 'redirect metrics'
            && $line['p95_redirect_ms'] === 95
            && $line['canary_samples'] === 100;
    })->once();
});

it('reports zero rather than dividing by it', function () {
    $this->artisan('redirect:metrics', ['--date' => metricsDate()])
        ->expectsOutputToContain('"bot_pct":0')
        ->assertSuccessful();
});

it('leaves the canary out of the pipeline entirely', function () {
    $this->seed(CanarySeeder::class);
    $slug = (string) config('health.canary.slug');

    $this->get("/x/{$slug}")->assertRedirect((string) config('health.canary.destination'));

    // 1440 scans a day for ever. Recorded, that is half a million junk rows a year in
    // scan_events and a permanent skew on every aggregate built on top of it.
    expect(Redis::connection()->llen(RedirectController::BUFFER_KEY))->toBe(0);

    dispatch_sync(new ProcessScanBuffer);

    expect(ScanEvent::count())->toBe(0);
});
