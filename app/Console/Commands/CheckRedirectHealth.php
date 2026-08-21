<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\RedirectCanaryFailed;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Scans our own QR code once a minute, the way a phone would.
 *
 * Over the PUBLIC url, never a loopback: the failures worth catching are the ones a
 * scanner meets and we do not — an expired certificate, a Cloudflare rule, a DNS
 * change, a WAF challenge served to a camera app. A canary that talks to 127.0.0.1
 * proves PHP is running and reports green through every one of them.
 */
class CheckRedirectHealth extends Command
{
    protected $signature = 'redirect:health';

    protected $description = 'Scan the canary QR code over the public URL and alert if it stops redirecting';

    private const FAILURE_KEY = 'health:failures';

    private const ALERTED_KEY = 'health:alerted-at';

    public function handle(): int
    {
        $url = rtrim((string) config('health.url'), '/').'/x/'.config('health.canary.slug');

        // Guarded everywhere but locally, so a staging box pointed at a loopback
        // cannot quietly report green either.
        if (! app()->isLocal() && ! $this->isPubliclyRoutable($url)) {
            // Refusing to run is the correct outcome: a green canary that never left
            // the network is worse than no canary, because it is believed. Checked as
            // a WHITELIST — public scheme, public address — rather than a blocklist of
            // loopback spellings, because 10.0.0.1, 169.254.169.254 and [::1] all
            // answer perfectly well from inside the box while Cloudflare is on fire.
            // Through the ordinary failure path, so it MAILS. Logging critical and
            // returning meant a misconfigured canary "ran" every minute for ever
            // while the redirect could be down for days, with the only record a log
            // line nobody reads at 3am — the failure mode config/health.php spends a
            // paragraph arguing against.
            return $this->failed($url, 'not publicly routable — set HEALTH_URL to the public https hostname');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withoutRedirecting()
                ->timeout((float) config('health.timeout'))
                ->withHeaders(['User-Agent' => 'kodeqr-canary/1.0'])
                ->get($url);

            $status = $response->status();
            $location = (string) $response->header('Location');
        } catch (Throwable $exception) {
            // Timeouts count toward the percentile too, clamped at the budget. A day
            // of total timeouts previously produced a p95 of null, which reads as
            // "no data" when it means "nothing was fast enough to finish".
            $this->sample((int) round((microtime(true) - $startedAt) * 1000));

            return $this->failed($url, $exception->getMessage());
        }

        $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
        $expected = (string) config('health.canary.destination');
        $edgeHeader = (string) config('health.edge_header');

        // Sampled before the assertions, not after. Only recording latency for runs
        // that PASSED made the percentile survivorship-biased in the worst direction:
        // a day spent at 4.9s, tripping the 5s timeout intermittently, reported a p95
        // built from precisely the runs that stayed fast.
        $this->sample($elapsed);

        if ($status !== 302) {
            return $this->failed($url, "expected 302, got {$status}");
        }

        // Cloudflare stamps this on everything it serves. Without it the request
        // reached the origin some other way — which is the whole outage class this
        // command exists for, arriving disguised as a pass.
        if ($edgeHeader !== '' && ! app()->isLocal() && $response->header($edgeHeader) === '') {
            return $this->failed($url, "response carried no [{$edgeHeader}] — the request did not go through the edge");
        }

        // The Location too, not just the status. A cache poisoned by a bad deploy, or
        // a code whose destination was edited by someone with database access, still
        // answers 302 — and every scanner lands somewhere we did not choose.
        if ($location !== $expected) {
            return $this->failed($url, "expected Location [{$expected}], got [{$location}]");
        }

        if ($elapsed > (int) config('health.slow_ms')) {
            // Logged, never alerted on. A slow redirect is worth knowing about; waking
            // somebody for one is how an alert channel gets muted.
            Log::warning('Redirect canary slow.', ['url' => $url, 'ms' => $elapsed]);
        }

        if (! $this->valkeyAcceptsWrites()) {
            return $this->failed($url, 'Valkey is not accepting writes — scans are being buffered nowhere');
        }

        $this->forget();
        $this->info("OK 302 -> {$location} ({$elapsed}ms)");

        return self::SUCCESS;
    }

    private function failed(string $url, string $reason): int
    {
        // The log line first, and unconditionally: it is the one record that survives
        // every other thing in this method failing.
        Log::critical('Redirect canary failed.', ['url' => $url, 'reason' => $reason]);

        $failures = $this->countFailure();
        $this->error("FAIL ({$failures}) {$reason}");

        $threshold = (int) config('health.failures_before_alert');
        $remind = max(1, (int) config('health.remind_every'));
        $lastAlertedAt = $this->readCounter(self::ALERTED_KEY);

        // Once at the threshold, then a reminder every half hour. One mail a minute
        // for the length of an outage is a filter rule waiting to be written.
        $due = $failures >= $threshold
            && ($lastAlertedAt === 0 || $failures - $lastAlertedAt >= $remind);

        // Recorded only when the send SUCCEEDS. Marking it as sent regardless meant a
        // single SMTP hiccup at failure 2 bought thirty minutes of silence before the
        // next attempt — during an outage, with nobody told.
        if ($due && $this->raiseAlert($url, $reason, $failures)) {
            $this->writeCounter(self::ALERTED_KEY, $failures);
        }

        return self::FAILURE;
    }

    /**
     * True only if somebody was actually told.
     */
    private function raiseAlert(string $url, string $reason, int $failures): bool
    {
        $address = config('health.alert_address');

        if (! is_string($address) || $address === '') {
            Log::critical('Redirect canary alert has nowhere to go.', ['reason' => $reason]);

            return false;
        }

        try {
            // Sent, not queued. The queue depends on the same infrastructure the
            // canary just found broken, and an alert that waits for a worker is an
            // alert that arrives after somebody else has noticed.
            Mail::to($address)->send(new RedirectCanaryFailed($url, $reason, $failures));

            return true;
        } catch (Throwable $exception) {
            Log::critical('Redirect canary alert could not be sent.', ['exception' => $exception->getMessage()]);

            return false;
        }
    }

    private function isPubliclyRoutable(string $url): bool
    {
        if (mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $host = mb_strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return false;
        }

        // An IP literal is never the public path: the public path has a name, and
        // Cloudflare answers for the name. Private and reserved ranges are rejected
        // for the obvious reason, and a public literal for the subtler one — it goes
        // straight to the origin and skips the edge entirely.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (['localhost', '.test', '.local', '.localhost', '.internal', '.lan'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return str_contains($host, '.');
    }

    /**
     * The half of the path a 302 cannot prove.
     *
     * The canary is excluded from scan recording, so it never exercises the `incr` and
     * `rpush` that every real scan performs. A Valkey serving reads but refusing
     * writes — over maxmemory, or a replica promoted read-only after a failover —
     * answers the redirect perfectly while every scan's buffer write fails silently
     * (record() only logs a warning) and the cap counter freezes. Without this the
     * loss would surface once a day, in a metrics line, the following morning.
     *
     * One key, overwritten every minute, expiring on its own.
     */
    private function valkeyAcceptsWrites(): bool
    {
        try {
            $redis = Redis::connection();
            $redis->setex('health:write-probe', 120, (string) now()->timestamp);

            return true;
        } catch (Throwable $exception) {
            Log::critical('Redirect canary write probe failed.', ['exception' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Latency samples for the daily p95, one a minute. Sampling the canary rather
     * than timing real scans keeps the measurement off the critical path entirely
     * (constraint 1) — 1440 samples a day is plenty for a percentile.
     */
    private function sample(int $ms): void
    {
        try {
            $key = 'health:latency:'.now()->timezone('Asia/Jakarta')->toDateString();
            $redis = Redis::connection();
            $redis->rpush($key, $ms);
            $redis->expire($key, 8 * 24 * 60 * 60);
        } catch (Throwable $exception) {
            Log::warning('Redirect canary could not record latency.', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * On disk, deliberately, while everything else in this app counts in Redis.
     *
     * Redis being down IS one of the outages this command exists to catch, and a
     * counter living there would reset to 1 on every run of that outage — never
     * reaching the threshold, never alerting, and reporting a fresh first failure
     * each minute for as long as it lasted. An alarm must not depend on the system
     * it watches.
     */
    private function counter(): Repository
    {
        return Cache::store('file');
    }

    private function countFailure(): int
    {
        $count = $this->readCounter(self::FAILURE_KEY) + 1;
        $this->writeCounter(self::FAILURE_KEY, $count);

        return $count;
    }

    /**
     * A read that cannot throw, and whose failure mode is LOUDER rather than quieter.
     *
     * If the cache directory is full, read-only or gone, returning 0 would keep every
     * failure looking like the first and never alert. Returning the threshold means an
     * unreadable counter alerts immediately — a duplicate mail is a nuisance, a silent
     * outage is the thing this command exists to prevent.
     */
    private function readCounter(string $key): int
    {
        try {
            return (int) $this->counter()->get($key, 0);
        } catch (Throwable $exception) {
            Log::warning('Redirect canary counter unreadable.', ['exception' => $exception->getMessage()]);

            return $key === self::FAILURE_KEY ? (int) config('health.failures_before_alert') : 0;
        }
    }

    private function writeCounter(string $key, int $value): void
    {
        try {
            // Long enough to survive a night's outage, short enough that a failure
            // months ago cannot make tonight's first miss look like the second.
            $this->counter()->put($key, $value, now()->addDay());
        } catch (Throwable $exception) {
            Log::warning('Redirect canary counter unwritable.', ['exception' => $exception->getMessage()]);
        }
    }

    private function forget(): void
    {
        try {
            $this->counter()->forget(self::FAILURE_KEY);
            $this->counter()->forget(self::ALERTED_KEY);
        } catch (Throwable) {
            //
        }
    }
}
