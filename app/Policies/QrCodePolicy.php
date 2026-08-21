<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QrCode;
use App\Models\User;

/**
 * Ownership, and nothing else.
 *
 * Every method answers the same question, which is deliberate: a QR code has exactly
 * one owner and there are no shared codes, no team seats and no admin UI yet. When
 * M4 adds teams this is the one file that changes.
 *
 * `viewAny` is absent on purpose — the index scopes to the owner rather than
 * authorising a collection, so there is no way to ask for somebody else's list.
 */
class QrCodePolicy
{
    public function view(User $user, QrCode $qrCode): bool
    {
        return $this->owns($user, $qrCode);
    }

    /**
     * Editing is ownership AND plan: a lapsed owner keeps their codes redirecting for
     * ever (constraint 8) but loses the ability to change where they point, which is
     * the entire difference between lapsed and free.
     */
    public function update(User $user, QrCode $qrCode): bool
    {
        return $this->owns($user, $qrCode) && $user->entitlements()->can('can_edit');
    }

    public function delete(User $user, QrCode $qrCode): bool
    {
        return $this->owns($user, $qrCode) && $user->entitlements()->can('can_edit');
    }

    /**
     * Strict, because both sides are integers and `0 == 'anything'` has been a real
     * bug in real applications. This is the single line standing between two accounts.
     */
    private function owns(User $user, QrCode $qrCode): bool
    {
        return $qrCode->user_id === $user->id;
    }
}
