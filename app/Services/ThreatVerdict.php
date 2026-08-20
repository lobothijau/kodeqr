<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The answer to "may this URL be a destination?" — with the third state that matters
 * most: we could not tell.
 *
 * `unknown` is not `safe`. Constraint 5 says every destination is checked, but a
 * provider outage must not block every signup, so an unknown verdict lets the save
 * through and hands the question to a recheck job ten minutes later (I5).
 */
final readonly class ThreatVerdict
{
    private function __construct(
        public bool $blocked,
        public bool $checked,
        public ?string $threatType = null,
    ) {}

    public static function safe(): self
    {
        return new self(blocked: false, checked: true);
    }

    public static function blocked(string $threatType): self
    {
        return new self(blocked: true, checked: true, threatType: $threatType);
    }

    public static function unknown(): self
    {
        return new self(blocked: false, checked: false);
    }
}
