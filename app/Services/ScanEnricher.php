<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ScanDevice;
use DeviceDetector\DeviceDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Turns one buffered scan payload into one scan_events row.
 *
 * Everything expensive lives here rather than on the redirect path: the user agent
 * is parsed, the bot list is applied and the once-a-day uniqueness key is claimed a
 * minute after the scanner has already been redirected (I3).
 *
 * A payload it cannot make sense of is dropped, never guessed at. Guessing writes a
 * row against the wrong code; dropping loses one scan and says so in the log.
 */
/**
 * Not final: the processor's "a failure between the LPOP and the insert still keeps
 * the chunk" test substitutes a mapper that throws, and a real one cannot be made to
 * fail on demand.
 */
class ScanEnricher
{
    /**
     * Case-insensitive substrings, matched against the raw user agent.
     *
     * Deliberately not DeviceDetector::isBot(): it answers false for `WhatsApp/2.x`,
     * which is the single most common non-human fetch this product sees — every
     * /x/ link pasted into a chat is previewed before anyone taps it.
     */
    private const BOT_SIGNATURES = [
        'whatsapp', 'facebookexternalhit', 'telegrambot', 'twitterbot', 'slackbot',
        'discordbot', 'linkedinbot', 'googlebot', 'bingbot', 'applebot', 'petalbot',
        'headless', 'curl', 'wget', 'python-requests', 'go-http-client', 'okhttp',
    ];

    /**
     * The key is (code, ip_hash) and ip_hash already carries the WIB day, so the key
     * IS the day bucket — the TTL only reclaims memory. Two days rather than one so
     * a backlog drained late still finds the claim it made: expiring at exactly 24h
     * would let a scan held through an outage past midnight claim a second time and
     * count one person twice.
     */
    private const UNIQUE_TTL = 172800;

    /**
     * The earliest timestamp worth believing. A payload older than the product is a
     * corrupt or hostile one, not a late scan.
     */
    private const EARLIEST_SCAN = 1735689600;

    /**
     * Keys claimed while mapping the chunk currently in flight. If its insert fails
     * the chunk is replayed, and a claim that outlived the failure would make every
     * first-visit-of-the-day in it a repeat visit — permanently, since scan_events is
     * append-only.
     *
     * @var array<int, string>
     */
    private array $claimed = [];

    /**
     * scan_events.os and .browser are varchar(32).
     */
    private const LABEL_LIMIT = 32;

    /**
     * Parsing is by far the most expensive thing in the pipeline — hundreds of regex
     * definitions per call — and a chunk of 500 scans off one printed code is mostly
     * the same handful of user agents. Bounded so a long drain cannot grow it without
     * limit.
     *
     * @var array<string, array{device: string, os: string|null, browser: string|null}>
     */
    private array $agents = [];

    private const AGENT_MEMO_LIMIT = 1_000;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function toRow(array $payload): ?array
    {
        $qrCodeId = $this->string($payload, 'qr_id');
        $eventUuid = $this->string($payload, 'uuid');
        $ipHash = $this->string($payload, 'ip_hash');
        $timestamp = $payload['t'] ?? null;

        // char(26), char(64) and a foreign key. Under MySQL strict mode a wrong
        // length throws on insert, which would cost the whole chunk rather than the
        // one payload that was malformed.
        if (! Str::isUlid($qrCodeId) || ! Str::isUlid($eventUuid) || ! is_int($timestamp)) {
            return null;
        }

        // MySQL's DATETIME stops at 9999-12-31; a hostile `t` of 253402300800 formats
        // to year 10000, which INSERT IGNORE would drop while the counter still
        // counted it. A scan cannot predate the product or come from tomorrow.
        if ($timestamp < self::EARLIEST_SCAN || $timestamp > now()->addDay()->timestamp) {
            return null;
        }

        if (preg_match('/^[0-9a-f]{64}$/', $ipHash) !== 1) {
            return null;
        }

        $userAgent = $this->string($payload, 'ua');
        $isBot = $this->isBot($userAgent);

        return [
            'qr_code_id' => $qrCodeId,
            'event_uuid' => $eventUuid,
            'occurred_at' => Carbon::createFromTimestamp($timestamp, 'UTC')->format('Y-m-d H:i:s'),
            'ip_hash' => $ipHash,
            // M1-T9 fills these from GeoLite2. Until then the pipeline stores what it
            // knows, which is nothing — the schema already allows it and the task's
            // own rule is that a failed lookup never drops an event.
            'country' => null,
            'region' => null,
            'city' => null,
            ...$this->agent($userAgent),
            'is_unique' => $isBot ? false : $this->claimUnique($qrCodeId, $ipHash),
            'is_bot' => $isBot,
            'referer' => $this->label($this->string($payload, 'ref'), 255),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{device: string, os: string|null, browser: string|null}
     */
    private function agent(string $userAgent): array
    {
        if ($userAgent === '') {
            return ['device' => ScanDevice::Other->value, 'os' => null, 'browser' => null];
        }

        if (isset($this->agents[$userAgent])) {
            return $this->agents[$userAgent];
        }

        $detector = new DeviceDetector($userAgent);
        $detector->parse();

        $os = $detector->getOs('name');
        $browser = $detector->getClient('name');

        $agent = [
            'device' => $this->device($detector->getDeviceName()),
            'os' => $this->label(is_string($os) ? $os : '', self::LABEL_LIMIT),
            'browser' => $this->label(is_string($browser) ? $browser : '', self::LABEL_LIMIT),
        ];

        if (count($this->agents) < self::AGENT_MEMO_LIMIT) {
            $this->agents[$userAgent] = $agent;
        }

        return $agent;
    }

    /**
     * DeviceDetector knows a dozen device types; scan_events knows four. Anything
     * that is not clearly a phone, tablet or computer is `other` rather than a guess.
     */
    private function device(string $detected): string
    {
        return match ($detected) {
            'smartphone', 'phablet', 'feature phone' => ScanDevice::Mobile->value,
            'tablet' => ScanDevice::Tablet->value,
            'desktop' => ScanDevice::Desktop->value,
            default => ScanDevice::Other->value,
        };
    }

    private function isBot(string $userAgent): bool
    {
        return Str::contains(Str::lower($userAgent), self::BOT_SIGNATURES);
    }

    /**
     * SET NX EX: the first event for this code and hashed address today wins, every
     * later one is a repeat visit. Atomic, so two workers processing two chunks
     * cannot both call the same scanner unique.
     *
     * Bots never claim the key. A link preview arriving before the human would
     * otherwise spend the uniqueness on a fetch no dashboard ever shows.
     */
    private function claimUnique(string $qrCodeId, string $ipHash): bool
    {
        // Through command() rather than the connection's set() wrapper: the wrapper
        // reorders the arguments into phpredis's options array itself, and Laravel
        // types set() with the native two-argument signature, so the NX+EX form only
        // type-checks when it is handed over as the native call it already is.
        $key = "uq:{$qrCodeId}:{$ipHash}";

        $claimed = (bool) Redis::connection()->command('set', [$key, 1, ['NX', 'EX' => self::UNIQUE_TTL]]);

        if ($claimed) {
            $this->claimed[] = $key;
        }

        return $claimed;
    }

    /**
     * Called by the processor before it maps a chunk.
     */
    public function forgetClaims(): void
    {
        $this->claimed = [];
    }

    /**
     * Called by the processor when a chunk's insert failed and the chunk is going
     * back on the buffer: the claims it made must not survive to make the replay
     * record every one of them as a repeat visit.
     */
    public function releaseClaims(): void
    {
        if ($this->claimed !== []) {
            Redis::connection()->del(...$this->claimed);
        }

        $this->claimed = [];
    }

    private function label(string $value, int $limit): ?string
    {
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
