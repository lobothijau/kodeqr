<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Controllers\RedirectController;
use App\Models\QrCode;
use App\Models\ScanEvent;
use App\Services\ScanEnricher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Drains the list the redirect path fills (constraint 9).
 *
 * Everything the scanner did not wait for happens here: user agent parsing, the bot
 * filter, the daily uniqueness claim, one bulk insert per chunk, and one UPDATE per
 * chunk for the counters. Runs every minute, so a minute of scans is the most that
 * can ever be in flight.
 *
 * Idempotent by construction. LPOP is atomic, so two workers cannot take the same
 * chunk; already-inserted event ids are filtered out before the insert, and
 * insertOrIgnore covers the race between two workers holding the same replayed
 * payload. Replaying a chunk produces the same rows AND the same scan_count —
 * counting rows instead of inserts is what would quietly corrupt a plan's cap.
 */
final class ProcessScanBuffer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Daily counters for M1-T8's metrics line, kept here because this job already
     * knows every number: nothing is added to the redirect path to produce them
     * (constraint 1). WIB-dated to match every other day bucket in the product.
     */
    public static function metricKey(string $name, string $date): string
    {
        return "scans:metrics:{$date}:{$name}";
    }

    /**
     * Unique on execution, not on dispatch. The scheduler's withoutOverlapping only
     * guards the milliseconds it takes to queue this job; two workers actually
     * draining at once would each filter already-recorded ids before the other's
     * insert lands, and count the same event twice.
     */
    public int $uniqueFor = 900;

    /**
     * One attempt. A failed chunk is already back on the buffer, and the schedule
     * dispatches again in a minute — the buffer is the retry mechanism, so the queue
     * retrying as well would just race the next run for the same payloads.
     */
    public int $tries = 1;

    /**
     * Well above the worst case drain (10k payloads, each a Redis claim and possibly
     * a user agent parse). The default 60s would SIGKILL the worker mid-chunk, and a
     * killed process cannot requeue the chunk it is holding.
     */
    public int $timeout = 300;

    /**
     * One bulk insert per chunk. Big enough that a busy minute is a handful of
     * statements, small enough that a requeue on failure replays little.
     */
    public const CHUNK = 500;

    /**
     * A ceiling per run, not a target: a backlog that cannot be drained in a minute
     * is drained over several, rather than one run holding a worker for an hour.
     */
    public const MAX_PER_RUN = 10_000;

    public function handle(ScanEnricher $enricher): void
    {
        $drained = 0;

        while ($drained < self::MAX_PER_RUN) {
            /** @var mixed $chunk */
            $chunk = Redis::connection()->command('lpop', [RedirectController::BUFFER_KEY, self::CHUNK]);

            if (! is_array($chunk) || $chunk === []) {
                return;
            }

            $drained += count($chunk);

            $this->process(array_values(array_filter($chunk, is_string(...))), $enricher);
        }
    }

    /**
     * @param  array<int, string>  $chunk
     */
    private function process(array $chunk, ScanEnricher $enricher): void
    {
        $enricher->forgetClaims();

        $rows = [];
        $readable = [];
        $dropped = 0;
        $position = 0;

        // Everything after the LPOP lives inside the try. The chunk is already off
        // the list by now, so an exception while parsing a user agent or claiming a
        // uniqueness key would otherwise take those scans with it.
        try {
            foreach ($chunk as $index => $json) {
                // The item currently in hand. If mapping dies on it, it is part of
                // the tail that goes back rather than the part already accounted for.
                $position = $index;

                /** @var mixed $payload */
                $payload = json_decode($json, true);
                $row = is_array($payload) ? $enricher->toRow($payload) : null;

                if ($row === null) {
                    $dropped++;

                    continue;
                }

                // Keyed by event id: the same payload can appear twice in one chunk —
                // the redis connection retries a write whose reply timed out, so a
                // reply lost after the server ran the rpush leaves two copies. The
                // insert would collapse them and the counter would not.
                $rows[$row['event_uuid']] = $row;
                $readable[] = $json;
            }

            $position = count($chunk);

            if ($dropped > 0) {
                // Dropped, not requeued: a payload this job cannot read will not
                // become readable next minute, and returning it makes it immortal.
                Log::warning('Unreadable scan payloads dropped.', ['count' => $dropped]);
            }

            $inserted = [];

            if ($rows !== []) {
                DB::transaction(function () use ($rows, &$inserted): void {
                    $fresh = $this->insertable(array_values($rows));

                    if ($fresh === []) {
                        return;
                    }

                    ScanEvent::query()->insertOrIgnore($fresh);
                    $this->touchCodes($fresh);
                    $inserted = $fresh;
                });
            }

            // Every counter moves AFTER the commit. Incremented inside the
            // transaction, a rollback would report scans as processed that no row
            // records — and the requeued chunk would then count them a second time.
            $buffered = $this->byScanDate(array_values($rows));

            if ($dropped > 0) {
                // Unreadable payloads have no trustworthy timestamp of their own, so
                // they are attributed to now. They are never requeued, so they cannot
                // be counted twice.
                $today = now()->timezone('Asia/Jakarta')->toDateString();
                $buffered[$today] = ($buffered[$today] ?? 0) + $dropped;
            }

            $this->bump('buffered', $buffered);
            $this->bump('processed', $this->byScanDate($inserted));
            $this->bump('bots', $this->byScanDate($inserted, fn (array $row): bool => (bool) ($row['is_bot'] ?? false)));
        } catch (Throwable $exception) {
            // Everything still in play goes back: what mapped cleanly, plus the tail
            // this loop never reached — including the payload it died on, since a
            // mapping failure is usually Valkey rather than the payload. Only the
            // ones explicitly dropped as unreadable stay dropped, so a chunk that
            // fails repeatedly does not re-log the same corrupt payloads every time.
            //
            // The transaction means nothing landed; releasing the claims means the
            // replay can still call a first visit a first visit; and the event ids
            // mean it cannot double-count. Losing a minute of scans to a deadlock is
            // a choice, not an inevitability.
            // The scans go back FIRST. Releasing claims is its own Redis call, and
            // the most likely reason for being in this catch at all is Valkey being
            // unwell — a throw from the release would take the whole chunk with it,
            // which is the exact loss this block exists to prevent.
            $pending = [...$readable, ...array_slice($chunk, $position)];

            if ($pending !== []) {
                Redis::connection()->rpush(RedirectController::BUFFER_KEY, ...$pending);
            }

            try {
                $enricher->releaseClaims();
            } catch (Throwable) {
                // The claims expire on their own; the scans would not have.
            }

            throw $exception;
        }
    }

    /**
     * Bucketed by when the SCAN happened, in WIB, not by when this job got round to
     * it. A scan at 23:59:59 drained at 00:00:01 belongs to yesterday: dating it by
     * processing time would drop it out of the nightly report for a day that has
     * already been reported, and a backlog crossing midnight moves thousands at once.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  (callable(array<string, mixed>): bool)|null  $only
     * @return array<string, int>
     */
    private function byScanDate(array $rows, ?callable $only = null): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if ($only !== null && ! $only($row)) {
                continue;
            }

            $date = CarbonImmutable::parse((string) $row['occurred_at'], 'UTC')
                ->timezone('Asia/Jakarta')
                ->toDateString();

            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Best-effort by design. A metrics counter exists so a human can read one log line
     * a day; it must never be able to fail a chunk of real scans.
     *
     * @param  array<string, int>  $byDate
     */
    private function bump(string $name, array $byDate): void
    {
        try {
            $redis = Redis::connection();

            foreach ($byDate as $date => $by) {
                if ($by === 0) {
                    continue;
                }

                $key = self::metricKey($name, $date);
                $redis->incrby($key, $by);
                // Longer than the nightly command needs, so a missed run can still be
                // reported by hand the next day.
                $redis->expire($key, 8 * 24 * 60 * 60);
            }
        } catch (Throwable $exception) {
            Log::warning('Scan metrics counter failed.', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * Two indexed lookups per chunk, each closing a way this pipeline could go wrong
     * quietly:
     *
     * Already-recorded ids, because a replayed chunk inserts no rows — insertOrIgnore
     * sees to that — but would still add its scans to every counter.
     *
     * Vanished codes, because a foreign key violation is the one error INSERT IGNORE
     * does not swallow on SQLite and does swallow on MySQL. Left in, a payload for a
     * force-deleted code would be requeued, rethrown and retried every minute for
     * ever on one engine while vanishing on the other. Soft-deleted codes still have
     * rows and still count: their paper still exists.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function insertable(array $rows): array
    {
        $recorded = ScanEvent::query()
            ->whereIn('event_uuid', array_column($rows, 'event_uuid'))
            ->pluck('event_uuid')
            ->flip();

        $codes = QrCode::withTrashed()
            ->whereIn('id', array_unique(array_column($rows, 'qr_code_id')))
            ->pluck('id')
            ->flip();

        $insertable = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! $recorded->has($row['event_uuid']) && $codes->has($row['qr_code_id']),
        ));

        // Counted from the rows themselves rather than by subtracting two totals: the
        // recorded set counts distinct ids, and mixing the two reports orphans for
        // codes that exist perfectly well — a false alarm on the one path whose whole
        // point is that dropped scans are visible.
        $orphans = count(array_filter($rows, fn (array $row): bool => ! $codes->has($row['qr_code_id'])));

        if ($orphans > 0) {
            Log::warning('Scans dropped for codes that no longer exist.', ['count' => $orphans]);
        }

        return $insertable;
    }

    /**
     * One UPDATE for the chunk, not one per event.
     *
     * scan_count counts bot fetches too, deliberately: the Redis counter that
     * enforces the plan cap increments before anything knows what a user agent is,
     * and a scan_count that disagreed with it would re-seed the cap wrong on the
     * next cache fill. Dashboards exclude bots; the quota cannot.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function touchCodes(array $rows): void
    {
        $counts = [];
        $latest = [];

        foreach ($rows as $row) {
            $id = (string) $row['qr_code_id'];
            $occurredAt = (string) $row['occurred_at'];

            $counts[$id] = ($counts[$id] ?? 0) + 1;
            $latest[$id] = max($latest[$id] ?? '', $occurredAt);
        }

        $ids = array_keys($counts);
        $countCase = '';
        $seenCase = '';
        $bindings = [];

        foreach ($ids as $id) {
            $countCase .= ' when ? then ?';
            $bindings[] = $id;
            $bindings[] = $counts[$id];
        }

        foreach ($ids as $id) {
            // Chunks can arrive out of order, and an older scan must not drag a
            // code's last_scanned_at backwards. GREATEST is MySQL-only and SQLite's
            // MAX() is aggregate-only there, so the comparison is spelled out.
            $seenCase .= ' when id = ? and (last_scanned_at is null or last_scanned_at < ?) then ?';
            $bindings[] = $id;
            $bindings[] = $latest[$id];
            $bindings[] = $latest[$id];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        DB::update(
            "update qr_codes set scan_count = scan_count + case id{$countCase} else 0 end,"
            ." last_scanned_at = case{$seenCase} else last_scanned_at end"
            ." where id in ({$placeholders})",
            [...$bindings, ...$ids],
        );
    }
}
