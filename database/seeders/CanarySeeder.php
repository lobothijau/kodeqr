<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Package;
use App\Enums\Plan;
use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Http\Controllers\RedirectController;
use App\Models\QrCode;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DestinationRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The one code kodeqr scans itself. Idempotent — deploy runs it every release.
 *
 * Paid tier on purpose: a free owner gets M1-T6's interstitial, so the canary would
 * assert a 302 against a code that answers 200 and page every minute for ever.
 *
 * Nothing here relies on model events. DatabaseSeeder uses WithoutModelEvents and
 * that propagates into nested call()s, so an observer-rendered `dest_url` would come
 * out missing and every scan of the canary would serve the unavailable page — a trap
 * that only springs on the deploy path, never in the test that seeds this directly.
 */
class CanarySeeder extends Seeder
{
    public function __construct(private readonly DestinationRenderer $destinations) {}

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'canary@kodeqr.com'],
            ['name' => 'kodeqr canary', 'password' => bcrypt(str()->random(64)), 'email_verified_at' => now()],
        );

        Subscription::updateOrCreate(
            ['user_id' => $user->id],
            [
                'plan' => Plan::Business,
                'package' => Package::TwelveMonths,
                'starts_at' => now(),
                // Far enough out that nobody has to remember it. A lapsed canary would
                // start answering with the interstitial and alert every minute.
                'ends_at' => now()->addYears(50),
            ],
        );

        $slug = (string) config('health.canary.slug');
        $existing = QrCode::withTrashed()->where('slug', $slug)->first();

        // The canary slug is inside the generator's own alphabet, so a real customer
        // code could hold it. Adopting that row would reassign it to us and rewrite
        // its destination — someone's printed paper, silently repointed.
        if ($existing !== null && $existing->user_id !== $user->id) {
            throw new RuntimeException("Slug [{$slug}] belongs to another account. Set HEALTH_CANARY_SLUG to a free one.");
        }

        $code = $existing ?? new QrCode;
        $code->slug = $slug;
        $code->user_id = $user->id;
        $code->type = QrCodeType::Url;
        $code->destination = $this->destinations->render(
            QrCodeType::Url,
            ['url' => (string) config('health.canary.destination')],
        );
        // Reset, not left alone: a canary flipped to blocked or over_quota answers 410
        // and pages every half hour for ever, and re-running this seeder is the
        // documented remedy for exactly that.
        $code->status = QrCodeStatus::Active;
        $code->deleted_at = null;
        $code->save();

        // Explicit, because the observer that normally does it may be muted here.
        Cache::forget(RedirectController::cacheKey($slug));
    }
}
