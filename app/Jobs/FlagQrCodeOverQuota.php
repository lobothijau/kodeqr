<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QrCodeStatus;
use App\Models\QrCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Makes the dashboard agree with what scanners are already seeing.
 *
 * The cap itself is enforced at the redirect by a Redis counter, synchronously and
 * atomically; this job only writes that outcome down. It is dispatched once, by the
 * single request that observes the counter crossing the cap — nothing on the scan
 * path waits for it, and a queue that is down delays the dashboard, never a scanner.
 */
final class FlagQrCodeOverQuota implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $qrCodeId) {}

    public function handle(): void
    {
        $code = QrCode::query()->find($this->qrCodeId);

        // Only `active` is ours to move. A code blocked for abuse between the scan
        // and this job must stay blocked: over_quota shows the scanner a milder page
        // than the one an abuse report earned it (constraints 5 and 8).
        if ($code === null || $code->status !== QrCodeStatus::Active) {
            return;
        }

        // Re-read the cap rather than trusting the one the scan was judged against:
        // an owner who upgraded in the seconds between that scan and this job has no
        // cap any more, and flipping their code now would leave a paying customer's
        // printed code showing "tidak aktif" with nothing to clear it (constraint 8).
        if ($code->user->entitlements()->limit('scan_cap_per_code') === null) {
            return;
        }

        $code->status = QrCodeStatus::OverQuota;
        $code->save();
    }
}
