<?php

declare(strict_types=1);

namespace App\Actions\QrCodes;

use App\Models\QrCode;
use App\Models\User;
use App\Services\SlugGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * The one seam that creates a QR code, and the only place a slug collision can be
 * survived.
 *
 * SlugGenerator's pre-check cannot see an insert that has not committed yet, so two
 * concurrent creates can both come back clean and one still loses to the UNIQUE index.
 * That loser is caught here and retried — the index is the authority, the pre-check is
 * only there to keep the common case off the error path. MySQL raises 1062 for this;
 * SQLite raises "UNIQUE constraint failed", and Laravel maps both to the same
 * exception, which is why the retry is testable on either engine.
 *
 * Deliberately not doing plan-limit checks: the count-then-write race on
 * `create-qr-code` belongs to M2-T1/M4-T3 and is already logged in docs/BACKLOG.md.
 */
final class CreateQrCode
{
    /**
     * Three inserts losing the same race would mean the RNG, not contention. `slug` is
     * the only unique key besides the ULID primary key, so there is nothing else this
     * retry could be silently papering over.
     */
    private const ATTEMPTS = 3;

    public function __construct(private readonly SlugGenerator $slugs) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $owner, array $attributes): QrCode
    {
        // The slug is the generator's to give: an owner-supplied one would let a
        // caller squat a string, and status belongs to the billing and quota state
        // machines (constraint 8) — neither is fillable by accident here.
        $code = new QrCode(Arr::except($attributes, ['slug', 'user_id', 'status']));
        $code->user()->associate($owner);

        // Assigned here rather than left to QrCodeObserver::creating(), because the
        // retry below has to hand out the next candidate from the same generator this
        // action was given. The observer stays the backstop for every write that does
        // not come through here — factories, imports, tinker.
        $code->slug = $this->slugs->make();

        for ($attempt = 1; ; $attempt++) {
            try {
                // A listener returning false halts the insert and save() reports it
                // in its return value, not by throwing. Returning $code regardless
                // would hand the caller a model that looks created while qr_codes
                // has no row — a builder that says "saved" over an empty table.
                if ($code->save()) {
                    return $code;
                }

                throw new RuntimeException('QR code creation was halted by a model event listener.');
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }

                // A fresh make(), not a fixed 7-char fallback: make() re-runs the
                // pre-check, so it escalates by itself if the table really is dense.
                $code->slug = $this->slugs->make();
            }
        }
    }
}
