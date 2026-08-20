<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AbuseSource;
use App\Models\AbuseFlag;
use App\Models\QrCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Constraint 5's gate, standing where Google Safe Browsing stood in the task file.
 *
 * Safe Browsing needs a Google Cloud project and API key, which this project does not
 * open (.ai/rules/general.md), and every free no-account threat feed — OpenPhish,
 * URLhaus, PhishTank — has since moved behind registration. What is left, and what
 * this uses, is Cloudflare's security resolver:
 * it answers 0.0.0.0 with EDE 16 ("Censored") for hosts in Cloudflare's malware and
 * phishing intelligence, needs no account, and the product's traffic already runs
 * through Cloudflare.
 *
 * The trade-off is real and worth knowing before trusting this: the check is
 * DOMAIN-level. A phishing page hosted in a folder on a compromised but otherwise
 * legitimate site passes, where a URL-level service would have caught it. M4-T6's
 * nightly re-scan is where deeper checking belongs.
 */
final class ThreatCheck
{
    /**
     * RFC 8914 Extended DNS Error 16: the answer was filtered by policy. Cloudflare
     * returns 0.0.0.0 for a blocked host, but 0.0.0.0 is also a legitimate answer for
     * a host that resolves nowhere, so the code is what distinguishes them.
     */
    private const EDE_CENSORED = 16;

    /**
     * DNS response codes worth forming a verdict from. 0 is an answer; 3 is
     * NXDOMAIN, which is a destination that does not resolve — the owner's problem,
     * not a threat. Anything else (2 SERVFAIL above all) is the resolver failing to
     * answer, and "no answer" must never be recorded as "clean".
     */
    private const ANSWERED = [0, 3];

    public function __construct(private readonly DestinationRenderer $destinations) {}

    public function check(string $url): ThreatVerdict
    {
        $host = $this->host($url);

        if ($host === null) {
            // Only reachable for input the renderer will refuse outright at save.
            // Nothing to ask a resolver about, and nothing that can be persisted.
            return ThreatVerdict::safe();
        }

        $cached = Cache::get($this->cacheKey($host));

        if ($cached === false) {
            return ThreatVerdict::safe();
        }

        if (is_string($cached)) {
            // The verdict is cached, not the word "cached": abuse_flags.threat_type
            // has to say what the finding was, on the hundredth refusal as on the
            // first.
            return ThreatVerdict::blocked($cached);
        }

        try {
            $response = Http::timeout((int) config('services.threat_check.timeout'))
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->get((string) config('services.threat_check.resolver'), ['name' => $host, 'type' => 'A']);

            if ($response->failed()) {
                return $this->unknown($host, 'http '.$response->status());
            }

            $payload = $response->json();

            // A 200 is not an answer. A captive portal, a proxy error page or a
            // SERVFAIL all arrive as 200-with-something, and caching any of them as
            // clean for a day is how a malicious domain gets a free pass.
            if (! is_array($payload) || ! in_array($payload['Status'] ?? null, self::ANSWERED, true)) {
                return $this->unknown($host, 'unusable answer');
            }

            $threatType = $this->isCensored($payload) ? 'dns_filtered' : false;

            Cache::put($this->cacheKey($host), $threatType, (int) config('services.threat_check.cache_ttl'));

            return $threatType === false ? ThreatVerdict::safe() : ThreatVerdict::blocked($threatType);
        } catch (Throwable $exception) {
            return $this->unknown($host, $exception->getMessage());
        }
    }

    /**
     * Records the verdict so an owner cannot quietly retry a blocked URL until it
     * sticks, and so M1-T7's reports and this share one table.
     */
    public function flag(string $url, ThreatVerdict $verdict, ?QrCode $code = null): void
    {
        AbuseFlag::query()->create([
            'qr_code_id' => $code?->id,
            'url' => mb_substr($url, 0, 2048),
            'source' => AbuseSource::ThreatCheck,
            'threat_type' => $verdict->threatType,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isCensored(array $payload): bool
    {
        // Comment arrives as a list from Cloudflare and as a bare string from other
        // DoH servers. Iterating a string raises a warning, which Laravel turns into
        // an ErrorException, which the catch below would file as "resolver
        // unavailable" — reporting every censored domain as unknown and letting all
        // of them through with only a log line to show for it.
        $comments = $payload['Comment'] ?? [];
        $comments = is_array($comments) ? $comments : [$comments];

        foreach ($comments as $comment) {
            if (is_string($comment) && str_contains($comment, 'EDE('.self::EDE_CENSORED.')')) {
                return true;
            }
        }

        // Some resolvers put the code in a structured field instead of the comment.
        $errors = $payload['Extended_DNS_Errors'] ?? [];

        foreach (is_array($errors) ? $errors : [] as $error) {
            if (is_array($error) && ($error['InfoCode'] ?? null) === self::EDE_CENSORED) {
                return true;
            }
        }

        return false;
    }

    private function unknown(string $host, string $reason): ThreatVerdict
    {
        // Fail-open, deliberately. A resolver outage that blocked every save would
        // take the product down for everyone to stop an attacker who has other
        // options; the recheck job closes the window instead.
        Log::warning('Destination threat check unavailable.', ['host' => $host, 'reason' => $reason]);

        return ThreatVerdict::unknown();
    }

    /**
     * The host of the URL that will actually be STORED, not of the string the owner
     * typed. The renderer prepends https to scheme-less input, so `malware.test/x`
     * parses to no host at all here while being saved as a working destination —
     * checking the raw string would wave every scheme-less URL straight through.
     */
    private function host(string $url): ?string
    {
        try {
            $host = parse_url($this->destinations->normalizeUrl($url), PHP_URL_HOST);
        } catch (Throwable) {
            // Unrenderable: the save is going to refuse it anyway.
            return null;
        }

        return is_string($host) && $host !== '' ? mb_strtolower($host) : null;
    }

    private function cacheKey(string $host): string
    {
        return 'threat:'.hash('sha256', $host);
    }
}
