<?php

declare(strict_types=1);

namespace App\Observers;

use App\Http\Controllers\RedirectController;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;

/**
 * The redirect cache stores the owner's plan so the warm path never needs the user,
 * the subscription or config to decide anything. That entry lives for six hours,
 * which is six hours longer than billing state is allowed to be wrong: a renewal must
 * restore everything in the same request, and expiry is exact with no grace period.
 *
 * So a write to a subscription drops every one of that owner's cached codes. Owners
 * buy a handful of times a year and own hundreds of codes at most, so this is a rare
 * query in exchange for billing state that is never stale.
 */
class SubscriptionObserver
{
    public function saved(Subscription $subscription): void
    {
        $this->forgetOwnersCodes($subscription);
    }

    public function deleted(Subscription $subscription): void
    {
        $this->forgetOwnersCodes($subscription);
    }

    private function forgetOwnersCodes(Subscription $subscription): void
    {
        $subscription->user?->qrCodes()
            ->withTrashed()
            ->pluck('slug')
            ->each(fn (string $slug) => Cache::forget(RedirectController::cacheKey($slug)));
    }
}
