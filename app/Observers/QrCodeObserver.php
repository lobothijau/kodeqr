<?php

declare(strict_types=1);

namespace App\Observers;

use App\Http\Controllers\RedirectController;
use App\Models\QrCode;
use Illuminate\Support\Facades\Cache;

/**
 * What makes "edit the destination, re-scan the printed paper, land somewhere new in
 * seconds" true. Any write to a code drops its cache entry, including the slug it
 * used to have, so a rename cannot leave the old key answering with stale data.
 */
class QrCodeObserver
{
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
