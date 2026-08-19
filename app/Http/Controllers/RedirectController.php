<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\QrCodeStatus;
use App\Models\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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

    public function __invoke(string $slug): SymfonyResponse
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
     * @return array{dest_url: mixed, status: mixed, plan: mixed, scan_cap: mixed, scan_count_key: mixed}|null
     */
    private function resolve(string $slug): ?array
    {
        $cached = Cache::get(self::cacheKey($slug));

        if (is_array($cached)) {
            /** @var array{dest_url: mixed, status: mixed, plan: mixed, scan_cap: mixed, scan_count_key: mixed} $cached */
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
            'dest_url' => $code->destination['dest_url'] ?? null,
            'status' => $code->status->value,
            'plan' => $code->user->currentPlan()->value,
            'scan_cap' => $code->user->entitlements()->limit('scan_cap_per_code'),
            'scan_count_key' => "scans:count:{$code->id}",
        ];

        Cache::put(self::cacheKey($slug), $payload, self::CACHE_TTL);

        return $payload;
    }

    private function isSafeDestination(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
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

    public static function cacheKey(string $slug): string
    {
        return "qr:{$slug}";
    }
}
