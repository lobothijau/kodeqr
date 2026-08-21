<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\RedirectController;
use App\Jobs\ProcessScanBuffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * One log line a day. There is no dashboard and will not be one before launch —
 * the point is that a person reading logs can see the pipeline is alive and roughly
 * healthy without querying anything.
 *
 * Reports on YESTERDAY in WIB, run just after midnight Jakarta, so the numbers cover
 * a whole day rather than however much of today has happened.
 */
class LogRedirectMetrics extends Command
{
    protected $signature = 'redirect:metrics {--date= : WIB date to report, defaults to yesterday}';

    protected $description = 'Log a daily line of redirect and scan-pipeline counters';

    public function handle(): int
    {
        $date = $this->option('date')
            ?? now()->timezone('Asia/Jakarta')->subDay()->toDateString();

        try {
            $redis = Redis::connection();

            $buffered = (int) ($redis->get(ProcessScanBuffer::metricKey('buffered', $date)) ?? 0);
            $processed = (int) ($redis->get(ProcessScanBuffer::metricKey('processed', $date)) ?? 0);
            $bots = (int) ($redis->get(ProcessScanBuffer::metricKey('bots', $date)) ?? 0);
            // Named for what it is: the depth right now, not for $date. On a
            // --date backfill it is today's queue printed beside a week-old line,
            // which is the sort of number somebody reads once at 3am and acts on.
            $backlogNow = (int) $redis->llen(RedirectController::BUFFER_KEY);

            /** @var array<int, string> $samples */
            $samples = $redis->lrange('health:latency:'.$date, 0, -1);
        } catch (Throwable $exception) {
            // The metrics line is not worth an exception in the scheduler, which would
            // mark the whole tick failed and hide anything scheduled after it.
            Log::warning('Redirect metrics unavailable.', ['exception' => $exception->getMessage()]);

            return self::FAILURE;
        }

        $line = [
            'date' => $date,
            'scans_buffered' => $buffered,
            'scans_processed' => $processed,
            // Of what was PROCESSED, not of what was buffered: unreadable payloads are
            // neither bot nor human and would drag the percentage toward a lie.
            'bot_pct' => $processed > 0 ? round($bots / $processed * 100, 1) : 0.0,
            'p95_redirect_ms' => $this->percentile($samples, 95),
            'canary_samples' => count($samples),
            'buffer_backlog_now' => $backlogNow,
        ];

        Log::info('redirect metrics', $line);
        $this->line(json_encode($line, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Nearest-rank, which needs no interpolation and cannot invent a latency that was
     * never measured.
     *
     * @param  array<int, string>  $samples
     */
    private function percentile(array $samples, int $percentile): ?int
    {
        if ($samples === []) {
            return null;
        }

        $values = array_map(intval(...), $samples);
        sort($values);

        $rank = (int) ceil($percentile / 100 * count($values));

        return $values[max(0, $rank - 1)];
    }
}
