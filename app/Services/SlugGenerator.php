<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\QrCode;
use RuntimeException;

/**
 * The 6-char public identity of every printed code. Once a slug is on paper it is
 * permanent, so generation is the one write-side decision that can never be revised.
 *
 * The alphabet is the constitution's 54 chars: digits and letters minus `0 1 I L O i
 * l o`, the pairs a human misreads off a receipt. 54^6 is about 2.4e10 (34.5 bits),
 * compared case-sensitively — see the ascii_bin collation on qr_codes.slug (M0-T2).
 */
/**
 * Not final: `random()` is the seam tests substitute to script a collision
 * deterministically, since a real one is a 1-in-2.4e10 event.
 */
class SlugGenerator
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';

    public const LENGTH = 6;

    /**
     * The router's constraint, and anything else that has to recognise a slug. Kept
     * here so a second reader of the alphabet cannot drift from the writer of it.
     */
    public const PATTERN = '[2-9A-HJKMNP-Za-hjkmnp-z]{6,8}';

    /**
     * Five collisions in a row against a table this sparse means the assumption is
     * wrong, not that we were unlucky. Widening to 7 chars (54^7, 2.1 bits more) costs
     * nothing — the /x/{slug} route already accepts {6,8}.
     */
    public const FALLBACK_LENGTH = 7;

    private const ATTEMPTS = 5;

    /**
     * A free slug, checked against what is already persisted.
     *
     * The check is an optimisation, not the guarantee: it cannot see a concurrent
     * insert that has not committed. The UNIQUE index is the guarantee, and
     * CreateQrCode retries on the violation it raises.
     */
    public function make(): string
    {
        foreach ([self::LENGTH, self::FALLBACK_LENGTH] as $length) {
            for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
                $slug = $this->random($length);

                if (! $this->taken($slug)) {
                    return $slug;
                }
            }
        }

        // Ten collisions across two lengths is a broken RNG, not bad luck. Handing
        // back a slug already known to be taken would push the failure onto the
        // UNIQUE index — or, on the observer path, onto a caller with no retry.
        throw new RuntimeException('Could not generate an unused slug.');
    }

    /**
     * Uniform over the alphabet via random_int (CSPRNG). Not `Str::random`, whose
     * alphabet is the one this project deliberately narrowed.
     */
    protected function random(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $slug = '';

        for ($i = 0; $i < $length; $i++) {
            $slug .= self::ALPHABET[random_int(0, $max)];
        }

        return $slug;
    }

    /**
     * Soft-deleted codes still own their slug: the paper they are printed on did not
     * disappear, and handing the string to someone else would redirect a stranger's
     * scan to a new destination.
     */
    private function taken(string $slug): bool
    {
        return QrCode::withTrashed()->where('slug', $slug)->exists();
    }
}
