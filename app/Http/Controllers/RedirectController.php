<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\QrCodeStatus;
use App\Jobs\FlagQrCodeOverQuota;
use App\Models\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * The product's contract with printed paper (invariants I1 and I2).
 *
 * On a warm cache this is one Redis read and a 302 — no SQL, no session, no CSRF, no
 * Inertia (see the `redirect` middleware group in bootstrap/app.php). Nothing here is
 * allowed to throw at a scanner: every failure degrades to a branded page.
 */
final class RedirectController extends Controller
{
    /**
     * Six hours. Long enough that a printed code's traffic is almost always a cache
     * hit; short enough that a stale entry the observer somehow missed self-heals
     * within a day.
     */
    private const CACHE_TTL = 6 * 60 * 60;

    /**
     * A slug that resolved to nothing is worth remembering briefly. Without this every
     * mis-scan, deleted code and fuzzed-but-well-formed slug is a MySQL query, forever,
     * from an unauthenticated route that exists to be pointed at by strangers. Short,
     * because the observer clears the key the moment such a code is created.
     */
    private const MISS_TTL = 60;

    private const MISS = 'miss';

    /**
     * The list M1-T4's processor drains. Named here because the redirect side is the
     * only writer, and a second name for the same list would silently lose scans.
     */
    public const BUFFER_KEY = 'scans:buffer';

    /**
     * Both the user agent and the referer are truncated to what scan_events.referer
     * can hold. mb_ so a multibyte UA is cut between characters — a half character
     * is invalid UTF-8, and json_encode answers that with `false`, not an exception.
     */
    private const HEADER_LIMIT = 255;

    public function __invoke(Request $request, string $slug): SymfonyResponse
    {
        // The whole body, not just the lookup: a payload whose shape drifted across a
        // deploy would otherwise throw on array access and 500 at printed paper (I2).
        try {
            $code = $this->resolve($slug);

            if ($code === null) {
                return $this->page('not-found', SymfonyResponse::HTTP_NOT_FOUND);
            }

            $status = $code['status'] ?? null;

            if ($status === QrCodeStatus::Blocked->value) {
                return $this->page('blocked', SymfonyResponse::HTTP_GONE);
            }

            if ($status !== QrCodeStatus::Active->value) {
                return $this->page('inactive', SymfonyResponse::HTTP_GONE);
            }

            $destination = $code['dest_url'] ?? null;

            // An active code with nowhere safe to go is a data bug, not a scanner's
            // problem. Safe Browsing (M1-T5) vets which sites are allowed; this vets
            // that the target is a site at all — javascript:, data: and a CR/LF in a
            // legacy or imported row must never reach a Location header.
            if (! is_string($destination) || ! $this->isSafeDestination($destination)) {
                return $this->page('unavailable');
            }

            // A lapsed owner's scans are not recorded at all — not the event, not
            // the counter. Their codes redirect forever for nothing, so serving one
            // has to stay close to free (documentation/billing.md). Everyone else is
            // recorded before the cap is judged, because the counter that judges it
            // is incremented by the same call that records: a scan over the cap still
            // happened, and the owner should see the demand they are turning away.
            // Named for what it changes, not for the plan behind it (constraint 7):
            // the plan that produces it stays in config.
            $recordsScans = ($code['records_scans'] ?? true) !== false;
            $count = $recordsScans ? $this->record($request, $slug, $code) : null;

            if ($this->isOverQuota($code, $count)) {
                return $this->page('inactive', SymfonyResponse::HTTP_GONE);
            }

            // The splash IS the scan: it is recorded above, once, server-side, and
            // the refresh below points straight at the destination rather than back
            // through this route — a second pass here would count every free scan
            // twice.
            if (($code['interstitial'] ?? false) === true) {
                return $this->splash($destination);
            }

            return $this->destination($destination);
        } catch (Throwable $exception) {
            // Even reporting is best-effort: a full disk must not turn a degraded
            // scan into a 500.
            try {
                report($exception);
            } catch (Throwable) {
                //
            }

            return $this->page('unavailable');
        }
    }

    /**
     * @return array{qr_id: mixed, dest_url: mixed, status: mixed, plan: mixed, scan_cap: mixed, scan_count_key: mixed, interstitial: mixed, records_scans: mixed}|null
     */
    private function resolve(string $slug): ?array
    {
        $cached = Cache::get(self::cacheKey($slug));

        if (is_array($cached)) {
            /** @var array{qr_id: mixed, dest_url: mixed, status: mixed, plan: mixed, scan_cap: mixed, scan_count_key: mixed, interstitial: mixed, records_scans: mixed} $cached */
            return $cached;
        }

        if ($cached === self::MISS) {
            return null;
        }

        // Cold path only. The owner's plan and cap are eager-loaded here so the warm
        // path never needs the user, the subscription or config for a decision.
        $code = QrCode::query()->with('user.subscription')->firstWhere('slug', $slug);

        if ($code === null) {
            Cache::put(self::cacheKey($slug), self::MISS, self::MISS_TTL);

            return null;
        }

        $payload = [
            'qr_id' => $code->id,
            'dest_url' => $code->destination['dest_url'] ?? null,
            'status' => $code->status->value,
            'plan' => $code->user->currentPlan()->value,
            'scan_cap' => $code->user->entitlements()->limit('scan_cap_per_code'),
            'scan_count_key' => "scans:count:{$code->id}",
            // Both resolved through Entitlements here, on the cold path, so the warm
            // path never needs a user, a subscription or a plan name to decide
            // anything (constraint 7).
            'interstitial' => $code->user->entitlements()->can('interstitial'),
            // The canary is scanned by us 1440 times a day, for ever. Recorded, it
            // would be roughly half a million junk rows a year in scan_events and a
            // permanent skew on every aggregate — and the alternative the task file
            // suggests, filtering owner=system in analytics, has to be remembered in
            // every query anyone writes from here to launch. Excluding it once, here
            // on the COLD path, means the warm path never learns the word canary.
            'records_scans' => $slug !== config('health.canary.slug')
                && $code->user->entitlements()->can('records_scans'),
        ];

        // Not for an owner whose scans are not recorded: nothing will ever increment
        // it, and their codes are served forever for nothing.
        //
        // Before the cache entry is published, not after: any scan that warm-hits in
        // between would INCR a key that does not exist yet, create it at 1, and the
        // SETNX would then no-op — handing a code that has used 480 of its 500 scans
        // a fresh 500. The window is one millisecond wide and reopens at every cache
        // expiry, which is exactly the kind of thing that is never reproducible.
        //
        // SETNX, because a counter that already exists is ahead of the database:
        // M1-T4's processor writes scan_count a minute behind.
        if ($payload['records_scans'] === true) {
            $this->seedScanCounter($payload['scan_count_key'], $code->scan_count);
        }

        Cache::put(self::cacheKey($slug), $payload, $this->ttlFor($code));

        return $payload;
    }

    /**
     * Six hours, unless the owner's package ends sooner.
     *
     * Lapsing is a clock event, not a write: `now() >= ends_at` and nothing in the
     * database changes, so no observer fires. Without this clamp a package that
     * expired at 09:00 would keep serving paid 302s — and recording scans that
     * documentation/billing.md says must not be recorded — until 15:00.
     */
    private function ttlFor(QrCode $code): int
    {
        $endsAt = $code->user->subscription?->ends_at;

        if ($endsAt === null || $endsAt->isPast()) {
            return self::CACHE_TTL;
        }

        // Integer timestamps rather than a Carbon diff: a TTL is whole seconds, and
        // the extra second puts the expiry just past the boundary rather than on it.
        $remaining = $endsAt->getTimestamp() - now()->getTimestamp() + 1;

        return max(1, min(self::CACHE_TTL, $remaining));
    }

    /**
     * Fire-and-forget scan capture (I3): the counter the cap is judged against, and
     * the payload M1-T4's processor drains. Two commands, no MySQL, nothing the
     * scanner waits on beyond the round-trips themselves.
     *
     * Every failure mode ends the same way — return null, log, let the scanner
     * through. A dead buffer must never become a dead code (I2).
     *
     * @param  array{qr_id: mixed, dest_url: mixed, status: mixed, plan: mixed, scan_cap: mixed, scan_count_key: mixed, interstitial: mixed, records_scans: mixed}  $code
     * @return int|null the scan count after this scan, or null when it could not be counted
     */
    private function record(Request $request, string $slug, array $code): ?int
    {
        $qrId = $code['qr_id'] ?? null;
        $countKey = $code['scan_count_key'] ?? null;

        // A payload cached before this task shipped has neither. Skipping the write
        // loses that entry's scans for up to six hours; guessing the key would count
        // them against the wrong code.
        if (! is_string($qrId) || $qrId === '' || ! is_string($countKey) || $countKey === '') {
            return null;
        }

        $count = null;

        try {
            $payload = json_encode([
                // 26 chars. Str::uuid() is 36 and scan_events.event_uuid is char(26),
                // so a uuid here means M1-T4's chunk is rejected by strict mode.
                'uuid' => (string) Str::ulid(),
                'slug' => $slug,
                'qr_id' => $qrId,
                't' => now()->timestamp,
                'ip_hash' => $this->ipHash($request),
                'ua' => $this->header($request->userAgent()),
                'ref' => $this->header($request->headers->get('referer')),
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                return null;
            }

            // Counter first, and assigned outside the try, so a failure of the second
            // command loses only the analytics event. Returning null here instead
            // would let the crossing scan through, and every later scan is already
            // past cap + 1 — so the row would never be flipped at all. Both commands
            // still sit under the one try/catch: covering only the rpush would let a
            // dead counter 500 at a scanner.
            $count = Redis::connection()->incr($countKey);
            Redis::connection()->rpush(self::BUFFER_KEY, $payload);
        } catch (Throwable $exception) {
            Log::warning('Scan buffer write failed.', [
                'slug' => $slug,
                'exception' => $exception->getMessage(),
            ]);
        }

        return is_int($count) ? $count : null;
    }

    /**
     * Fails open on purpose: an uncounted scan (Redis down, a pre-M1-T3 cache entry)
     * redirects. Constraint 8 says a scanner never hits a dead end, and refusing one
     * because our own counter is unavailable is exactly that.
     *
     * @param  array{qr_id: mixed, dest_url: mixed, status: mixed, plan: mixed, scan_cap: mixed, scan_count_key: mixed, interstitial: mixed, records_scans: mixed}  $code
     */
    private function isOverQuota(array $code, ?int $count): bool
    {
        $cap = $code['scan_cap'] ?? null;

        if (! is_int($cap) || $count === null || $count <= $cap) {
            return false;
        }

        // INCR is atomic, so exactly one request in a flood sees the crossing and
        // exactly one job is queued — not one per scan for as long as the flood runs.
        if ($count === $cap + 1 && is_string($code['qr_id'])) {
            // The dashboard row is the least important thing on this path. A queue
            // that is down (or a Valkey outage taking the queue with it) must not
            // throw into the outer catch and replace this scanner's correct 410 with
            // the generic unavailable page.
            try {
                FlagQrCodeOverQuota::dispatch($code['qr_id']);
            } catch (Throwable $exception) {
                Log::warning('Over-quota flag dispatch failed.', [
                    'qr_id' => $code['qr_id'],
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return true;
    }

    private function seedScanCounter(string $key, int $scanCount): void
    {
        try {
            Redis::connection()->setnx($key, $scanCount);
        } catch (Throwable $exception) {
            Log::warning('Scan counter seed failed.', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Constraint 3, and the only place a raw address appears at all: it lives in a
     * local for the length of one hash and is never written, queued or logged. The
     * date makes the hash a rotating salt, so yesterday's scanner cannot be joined
     * to today's.
     */
    private function ipHash(Request $request): string
    {
        // Cloudflare's header first: behind an orange cloud that is the visitor.
        // The fallback is the socket peer, deliberately NOT $request->ip(): with
        // trustProxies(at: '*') that reads X-Forwarded-For, which any client hitting
        // the origin directly can write. The one request that reaches the fallback is
        // by definition the one that bypassed the edge, so its own header is the last
        // thing to believe.
        $ip = $request->headers->get('CF-Connecting-IP')
            ?? $request->server->get('REMOTE_ADDR')
            ?? '';

        // A WIB day, not a UTC one. The hash is the day bucket every unique-scanner
        // count is built on, and a UTC bucket would roll over at 07:00 Jakarta —
        // splitting one person's morning and afternoon scans into two "uniques" and
        // never matching the WIB days the dashboards group by.
        $day = now()->timezone((string) config('app.display_timezone'))->format('Y-m-d');

        return hash('sha256', $day.config('app.key').$ip);
    }

    private function header(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, self::HEADER_LIMIT);
    }

    private function isSafeDestination(string $url): bool
    {
        // A backslash or userinfo makes PHP and a browser disagree about which host
        // this is. On the 302 path that was a wrong destination; on the splash it is
        // worse — the page would name `good.test` while the browser goes to
        // `evil.test`, turning the one honest moment in the flow into a lie. The
        // renderer refuses both, so only a legacy or hand-written row gets here.
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // A percent-escape in the authority is the same disagreement in a third form:
        // PHP hands back `%65vil.test` and a browser decodes it to `evil.test`, so the
        // splash would name a host nobody is going to. No real hostname contains one.
        if (str_contains((string) ($parts['host'] ?? ''), '%')) {
            return false;
        }

        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }

        // A stored `http:///etc/passwd` has a scheme and no authority. The renderer
        // refuses it on write; this is the guard for rows it never saw.
        return is_string(parse_url($url, PHP_URL_HOST)) && parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * `no-store` is load-bearing: a phone browser that caches this 302 keeps sending
     * the scanner to the old destination after an edit, which silently kills the one
     * thing a dynamic QR code is for.
     */
    private function destination(string $url): RedirectResponse
    {
        return (new RedirectResponse($url, SymfonyResponse::HTTP_FOUND))
            ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /**
     * The free-tier and lapsed interstitial.
     *
     * The destination is shown because a scanner deserves to know where a piece of
     * printed paper is about to send them — it is the only moment in the whole flow
     * where someone can still decline. `no-store` for the same reason as the 302: a
     * cached splash keeps naming the old destination after an edit.
     *
     * Split rather than printed whole. The host is the trust signal and is rendered
     * to dominate; the path follows it, quieter, because on this product the host
     * alone routinely says nothing — an Indonesian small business points its code at
     * instagram.com, wa.me, shopee.co.id or a Google Form, and every shop on the
     * street collapses to the same three hosts. The path is what distinguishes them.
     *
     * The order is the security property: an attacker controls the path and not the
     * host, so a path long enough to push the host out of view, or shaped to read
     * like one (evil.example/kodeqr.com/masuk), must never be the first thing the eye
     * lands on. Truncated here as well as clamped in CSS so a kilobyte of query
     * string cannot be used to bury what sits above it.
     */
    private function splash(string $destination): Response
    {
        $parts = parse_url($destination);

        $host = (string) ($parts['host'] ?? '');
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        // Punycode, the way a browser's address bar shows it. A homograph host
        // (kоdeqr.com with a Cyrillic о) passes every other guard on this path and
        // would render as an apparently-legitimate domain in the bold span directly
        // under a wordmark that now reads kodeqr.com. Ugly beats plausible here.
        // Best-effort: without ext-intl the raw host is still safer than no page.
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        // The port is part of the authority and is displayed with it: dropping it
        // named `toko.example` for a link going to `toko.example:8443`, a different
        // origin on the one page whose promise is that what it shows is where you are
        // going. A DEFAULT port is the opposite mistake — no browser shows `:443`, so
        // printing it puts suspicious noise on the trust line.
        $port = $parts['port'] ?? null;

        if ($port !== null && ! ($scheme === 'https' && $port === 443) && ! ($scheme === 'http' && $port === 80)) {
            $host .= ':'.$port;
        }

        $path = ($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        return response()
            ->view('redirect.splash', [
                'destination' => $destination,
                'host' => $host,
                // A bare "/" is not information, and it makes the host look truncated.
                // A single glyph, matching the one CSS draws when the clamp bites, so
                // the two truncations cannot be told apart.
                'path' => $path === '/' ? '' : Str::limit($path, 96, '…'),
            ])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Status pages are `no-store` for the same reason the 302 is: a cached "not
     * active" page outlives the pause that caused it, and the scanner has no way to
     * know they are looking at yesterday's answer.
     */
    private function page(string $view, int $status = SymfonyResponse::HTTP_OK): Response
    {
        return response()
            ->view("redirect.{$view}", [], $status)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Versioned. A payload cached before M1-T6 carries neither `interstitial` nor
     * `records_scans`, and the fallbacks would read as "paid" — so for six hours
     * after a deploy a lapsed owner's codes would skip the splash and record scans.
     * Bumping the prefix retires every stale entry at the moment of deploy.
     */
    public static function cacheKey(string $slug): string
    {
        return "qr:v2:{$slug}";
    }
}
