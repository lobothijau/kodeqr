<?php

declare(strict_types=1);

namespace App\Observers;

use App\Http\Controllers\RedirectController;
use App\Models\QrCode;
use App\Services\DestinationRenderer;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\Cache;

/**
 * Everything that must be true of a qr_codes row no matter who wrote it.
 *
 * Two jobs, both deliberately at the model-event layer rather than in a caller:
 *
 * 1. `dest_url` is rendered on every save (invariant I1). Putting it here — not in a
 *    service the builder is trusted to call, not in a controller — is what makes it
 *    impossible to persist a whatsapp destination the redirect path cannot use. An
 *    import, a tinker session and M2's builder all go through it.
 * 2. The cache entry is dropped on any write, including under the slug the code used
 *    to have, so a rename cannot leave the old key answering with stale data. This is
 *    what makes "edit the destination, re-scan the printed paper, land somewhere new
 *    in seconds" true.
 */
class QrCodeObserver
{
    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly DestinationRenderer $destinations,
    ) {}

    /**
     * A slug is assigned here, not by the caller. `slug` is not fillable either, so
     * a request payload cannot squat a string that will outlive it on paper.
     * Losing the race against the UNIQUE index is CreateQrCode's problem — retrying an
     * insert is not something a model event can do.
     */
    public function creating(QrCode $qrCode): void
    {
        if (blank($qrCode->slug)) {
            $qrCode->slug = $this->slugs->make();
        }
    }

    /**
     * Renders on update as well as create: an owner editing a whatsapp phone number
     * must not be able to leave yesterday's dest_url behind it.
     *
     * Only when those inputs actually changed, though. Re-rendering every save would
     * mean a row whose stored destination no longer renders — an import, a legacy
     * shape, a rule this renderer has since tightened — could never be written again,
     * including by the two writes that matter most: M1-T5 flipping it to `blocked`
     * and the quota job flipping it to `over_quota` (constraints 5 and 8). The code
     * you most need to block would be the one you can no longer touch.
     *
     * It also keeps a partially hydrated model out of trouble: M1-T4 bumping
     * `last_scanned_at` off a narrow select has no type or destination to render.
     */
    public function saving(QrCode $qrCode): void
    {
        if (! $qrCode->isDirty(['type', 'destination'])) {
            return;
        }

        $qrCode->destination = $this->destinations->render($qrCode->type, $qrCode->destination);
    }

    public function saved(QrCode $qrCode): void
    {
        $this->forget($qrCode);
    }

    public function deleted(QrCode $qrCode): void
    {
        $this->forget($qrCode);
    }

    public function restored(QrCode $qrCode): void
    {
        $this->forget($qrCode);
    }

    public function forceDeleted(QrCode $qrCode): void
    {
        $this->forget($qrCode);
    }

    private function forget(QrCode $qrCode): void
    {
        $original = $qrCode->getOriginal('slug');

        $slugs = array_unique(array_filter([
            $qrCode->slug,
            is_string($original) ? $original : null,
        ]));

        foreach ($slugs as $slug) {
            Cache::forget(RedirectController::cacheKey($slug));
        }
    }
}
