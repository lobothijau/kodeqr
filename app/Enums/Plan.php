<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Free and Lapsed are real plans for entitlement lookups (config/plans.php is keyed
 * by these) but neither is purchasable or storable.
 *
 * Free  = never paid. 3 codes, editing, 7-day analytics, splash, 500-scan cap.
 * Lapsed = paid before, package expired. Existing codes redirect forever behind the
 *          splash, but editing, analytics and new-code creation are off.
 *
 * The absence of a subscriptions row IS Free; a row past its ends_at IS Lapsed.
 * Storing either as a `plan` value would be a second representation of a state the
 * dates already answer.
 */
enum Plan: string
{
    case Free = 'free';
    case Lapsed = 'lapsed';
    case Regular = 'regular';
    case Plus = 'plus';
    case Business = 'business';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The only values the subscriptions.plan column accepts.
     *
     * @return array<int, string>
     */
    public static function purchasableValues(): array
    {
        return array_values(array_diff(
            self::values(),
            [self::Free->value, self::Lapsed->value],
        ));
    }

    /**
     * The tier to offer somebody who has just hit a limit.
     *
     * Free and Lapsed both point at Regular: one has never paid and one has stopped,
     * and in both cases Regular is the cheapest thing that unblocks them. Business
     * returns null — there is nothing left to sell, so the UI must say something else
     * rather than invent a tier.
     */
    public function upgradeTarget(): ?self
    {
        return match ($this) {
            self::Free, self::Lapsed => self::Regular,
            self::Regular => self::Plus,
            self::Plus => self::Business,
            self::Business => null,
        };
    }

    public function isPaid(): bool
    {
        return ! in_array($this, [self::Free, self::Lapsed], true);
    }
}
