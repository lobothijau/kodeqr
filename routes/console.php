<?php

use App\Console\Commands\CheckRedirectHealth;
use App\Console\Commands\LogRedirectMetrics;
use App\Jobs\ProcessScanBuffer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * The scan pipeline's heartbeat. A minute of scans is the most that can ever be in
 * flight.
 *
 * The drain itself is serialised by ShouldBeUnique on the job — this mutex only
 * guards the dispatch, and its TTL is five minutes rather than the framework's
 * default day: a scheduler killed mid-tick (deploy, OOM, container restart) would
 * otherwise hold the lock for 24 hours and silently stop the pipeline while
 * scans:buffer grew until Valkey evicted it.
 */
Schedule::job(new ProcessScanBuffer)->everyMinute()->withoutOverlapping(5);

/**
 * The canary. Every scan of every code in the product goes through the path this
 * checks, so it runs as often as the scheduler allows.
 *
 * NO `withoutOverlapping`, deliberately, and this is the one line where that matters:
 * the scheduler's mutex lives in the default cache store, which is Redis. Redis being
 * down is one of the outages this command exists to catch — and with a mutex, the
 * scheduler would fail to acquire it and the command would never run at all. The
 * alarm would go silent during precisely the failure it is watching for. Its own HTTP
 * timeout is five seconds, so a run cannot reach the next minute anyway.
 */
Schedule::command(CheckRedirectHealth::class)
    ->everyMinute()
    ->runInBackground();

/**
 * One line a day, covering yesterday in WIB. 00:10 Jakarta, not 00:00: the counters
 * are written by a job that runs on the minute, and reading them in the same minute
 * they roll over is how a day loses its last scans.
 */
Schedule::command(LogRedirectMetrics::class)
    ->dailyAt('00:10')
    ->timezone('Asia/Jakarta');
