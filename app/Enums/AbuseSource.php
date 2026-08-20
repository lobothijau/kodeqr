<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a flag came from. `threat_check` replaces the task file's `safe_browsing`:
 * Safe Browsing needs a Google Cloud project and API key, which this project does
 * not open (.ai/rules/general.md), so the automated check is Cloudflare's security
 * resolver instead.
 */
enum AbuseSource: string
{
    case ThreatCheck = 'threat_check';
    case Report = 'report';
    case Admin = 'admin';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
