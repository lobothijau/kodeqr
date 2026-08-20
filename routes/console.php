<?php

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
