<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Package;
use App\Enums\Plan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Typed reader over config/plans.php — the only file allowed to name a plan or an
 * amount (constraints 6 and 7).
 *
 * Reads never throw: an unknown or unconfigured plan falls back to free and logs,
 * because entitlement lookups sit under owner-facing pages and must degrade rather
 * than 500. Price lookups are the exception — a missing price means a checkout for
 * a product that has none, and charging nothing is worse than failing loudly.
 */
final class PlanConfig
{
    /**
     * Sentinel for a limit a lapsed account inherits from the tier it lapsed from,
     * so renewal restores history rather than finding it pruned to free's 7 days.
     */
    public const INHERIT = 'inherit';

    /**
     * @return array<string, mixed>
     */
    public static function features(Plan $plan): array
    {
        $features = config('plans.'.$plan->value);

        if (is_array($features)) {
            return $features;
        }

        Log::warning('No entitlements configured for plan; falling back to free.', [
            'plan' => $plan->value,
        ]);

        $fallback = config('plans.'.Plan::Free->value);

        return is_array($fallback) ? $fallback : [];
    }

    /**
     * Whether this plan has its own entitlements, as opposed to borrowing free's.
     * Callers that must not silently inherit free's numbers check here first.
     */
    public static function isConfigured(Plan $plan): bool
    {
        return is_array(config('plans.'.$plan->value));
    }

    /**
     * Integer rupiah. The client never sends an amount — it names a tier and a
     * package, and the server prices them here.
     */
    public static function price(Plan $tier, Package $package): int
    {
        $price = self::prices($tier)[$package->value] ?? null;

        if ($price === null) {
            throw new InvalidArgumentException(
                "Plan [{$tier->value}] has no price for package [{$package->value}]; it is not purchasable.",
            );
        }

        return $price;
    }

    /**
     * Empty for free and lapsed — neither is a product.
     *
     * @return array<string, int>
     */
    public static function prices(Plan $tier): array
    {
        $prices = self::features($tier)['prices'] ?? [];

        if (! is_array($prices)) {
            return [];
        }

        return array_filter($prices, is_int(...));
    }
}
