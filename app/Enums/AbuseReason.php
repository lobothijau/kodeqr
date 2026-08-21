<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a member of the public says a code is bad. Distinct from `threat_type`, which
 * is a machine verdict from the threat check — this is a human's account, and the
 * two disagreeing is itself information.
 */
enum AbuseReason: string
{
    case Phishing = 'phishing';
    case Malware = 'malware';
    case Penipuan = 'penipuan';
    case Lainnya = 'lainnya';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
