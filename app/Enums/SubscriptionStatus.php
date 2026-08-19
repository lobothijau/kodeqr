<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Materialized for querying and the expiry reminder emails. NOT the authority on
 * entitlements — Subscription::isActive() derives that from ends_at so a lapse or a
 * renewal is exact between cron runs.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Lapsed = 'lapsed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
