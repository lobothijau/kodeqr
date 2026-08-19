<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The single door to plan logic (constraint 7). Every surface — builder, exports,
 * dashboards, API — asks this service; nothing re-derives a limit or names a plan.
 *
 * Plan resolution itself belongs to User::currentPlan(): no row means free, a row
 * past its ends_at means lapsed. That answer is deliberately NOT memoised — it is a
 * date comparison against an already-loaded relation, and caching it would let a
 * package that expires mid-request keep handing out paid features, and a renewal
 * mid-request keep refusing them. Only the config lookup is memoised, keyed by plan,
 * because config is immutable for the request. Counts a write can invalidate (how
 * many codes exist) are not cached at all.
 */
final class Entitlements
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $features = [];

    public function __construct(private readonly User $user) {}

    public function plan(): Plan
    {
        return $this->user->currentPlan();
    }

    /**
     * Boolean features fail closed: an unknown key is a typo, and a typo must not
     * hand out an entitlement.
     */
    public function can(string $feature): bool
    {
        $features = $this->features();

        if (! array_key_exists($feature, $features)) {
            $this->warn('Unknown entitlement key; denying.', $feature);

            return false;
        }

        $value = $this->resolve($feature, $features[$feature]);

        if (is_bool($value)) {
            return $value;
        }

        $this->warn('Entitlement key is not a boolean; denying.', $feature);

        return false;
    }

    /**
     * Null means unlimited — a real answer for scan caps and Business retention.
     * An unknown key returns 0 rather than null so a typo denies instead of
     * granting infinity.
     */
    public function limit(string $key): ?int
    {
        $features = $this->features();

        if (! array_key_exists($key, $features)) {
            $this->warn('Unknown entitlement key; denying.', $key);

            return 0;
        }

        $value = $this->resolve($key, $features[$key]);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        $this->warn('Entitlement limit is not an integer; denying.', $key);

        return 0;
    }

    /**
     * `basic`, `advanced`, or null when the plan records nothing at all.
     */
    public function analyticsDepth(): ?string
    {
        $value = $this->resolve('analytics_depth', $this->features()['analytics_depth'] ?? null);

        return is_string($value) ? $value : null;
    }

    /**
     * Backs the `create-qr-code` gate. Soft-deleted codes free their slot; paused
     * and blocked ones do not, since they still exist and still redirect.
     */
    public function canCreateQrCode(): bool
    {
        $max = $this->limit('max_codes');

        if ($max === null) {
            return true;
        }

        return $this->user->qrCodes()->count() < $max;
    }

    /**
     * Expands the INHERIT sentinel; every other value is already final.
     */
    private function resolve(string $key, mixed $value): mixed
    {
        return $value === PlanConfig::INHERIT ? $this->inheritedValue($key) : $value;
    }

    /**
     * A lapsed account keeps the limit of the tier it lapsed from, read off its own
     * expired row. The DB enum guarantees that row's plan is a purchasable tier, so
     * this cannot resolve to another INHERIT.
     *
     * When it cannot resolve at all — no row, a non-tier plan, or that tier missing
     * from config — it returns null (unlimited/keep) rather than free's value or the
     * usual 0 deny. INHERIT exists only for retention, and every alternative answer
     * hands a pruning job a smaller number than the customer paid for: a misconfig
     * would then irreversibly delete history a renewal was supposed to restore.
     * Storage is cheap; the delete is not. The warning is the alarm.
     */
    private function inheritedValue(string $key): mixed
    {
        $tier = $this->user->subscription?->plan;

        if ($tier !== null && $tier->isPaid() && PlanConfig::isConfigured($tier)) {
            $features = PlanConfig::features($tier);

            if (array_key_exists($key, $features)) {
                return $features[$key];
            }
        }

        Log::warning('Could not inherit entitlement from the expired tier; preserving.', [
            'key' => $key,
            'tier' => $tier?->value,
            'user_id' => $this->user->id,
        ]);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function features(): array
    {
        $plan = $this->plan();

        return $this->features[$plan->value] ??= PlanConfig::features($plan);
    }

    private function warn(string $message, string $key): void
    {
        Log::warning($message, [
            'key' => $key,
            'plan' => $this->plan()->value,
        ]);
    }
}
