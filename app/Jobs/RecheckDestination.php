<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QrCodeStatus;
use App\Models\QrCode;
use App\Services\DestinationRenderer;
use App\Services\ThreatCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes the window the fail-open on create leaves behind (I5).
 *
 * A destination that saved because the resolver was unreachable is asked about
 * again, and kept being asked about until an answer arrives — a resolver outage
 * lasts longer than ten minutes far more often than not, and a single retry that
 * treats "still cannot tell" as "fine" leaves a malicious code redirecting for ever.
 *
 * It finds its codes by destination rather than by id, because on the create path
 * there is no id: validation runs before the row exists. Matching on the stored
 * dest_url also catches every other code pointing at the same bad URL.
 */
final class RecheckDestination implements ShouldQueue
{
    use Queueable;

    /**
     * Minutes to wait before asking again, per attempt. Front-loaded for the blip,
     * stretched out for the outage, and finite: something has to give up eventually
     * and say so loudly.
     *
     * @var array<int, int>
     */
    private const BACKOFF = [10, 30, 120, 360, 1440];

    public function __construct(
        private readonly string $url,
        private readonly int $attempt = 1,
    ) {}

    public function handle(ThreatCheck $threats, DestinationRenderer $destinations): void
    {
        $verdict = $threats->check($this->url);

        if (! $verdict->checked) {
            $this->retry();

            return;
        }

        if (! $verdict->blocked) {
            return;
        }

        try {
            $destination = $destinations->normalizeUrl($this->url);
        } catch (Throwable) {
            $destination = $this->url;
        }

        // Every code pointing at it, whatever state it is in. Skipping a paused or
        // over_quota code would leave a confirmed-malicious destination armed to
        // start serving the moment its owner resumes or renews — and constraint 8
        // guarantees they eventually will.
        $codes = QrCode::query()->where('destination->dest_url', $destination)->get();

        if ($codes->isEmpty()) {
            // Nothing points at it yet — a create whose row never landed, or one
            // already edited away. The URL is still worth recording.
            $threats->flag($this->url, $verdict);

            return;
        }

        foreach ($codes as $code) {
            // One flag per code, so an admin reading abuse_flags can see which codes
            // this URL took down rather than just that it was seen.
            $threats->flag($this->url, $verdict, $code);

            // `blocked` is left alone: it is already the answer this job wants.
            if ($code->status === QrCodeStatus::Blocked) {
                continue;
            }

            $code->status = QrCodeStatus::Blocked;
            $code->save();
        }
    }

    /**
     * Re-dispatches itself rather than throwing: a failed job is retried on the
     * queue's terms, and what this needs is a schedule of its own.
     */
    private function retry(): void
    {
        $next = self::BACKOFF[$this->attempt] ?? null;

        if ($next === null) {
            // Out of attempts with no answer either way. Loud, because a destination
            // nobody could check is exactly what I5 exists to notice.
            Log::critical('Destination never verified; giving up.', [
                'url' => $this->url,
                'attempts' => $this->attempt,
            ]);

            return;
        }

        self::dispatch($this->url, $this->attempt + 1)->delay(now()->addMinutes($next));
    }
}
