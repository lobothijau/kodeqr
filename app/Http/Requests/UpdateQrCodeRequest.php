<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Identical rules to create, by inheritance rather than by copy.
 *
 * The edit path is where constraint 5 is usually lost: a destination is saved clean,
 * passes review, and is then edited to a phishing page through a request class that
 * somebody forgot to attach SafeDestination to. There is nothing here to forget.
 */
class UpdateQrCodeRequest extends StoreQrCodeRequest
{
    /**
     * Ownership, not quota — and before validation, so probing somebody else's code
     * cannot spend a threat-check DNS lookup or read the shape of our rules back out
     * of the error bag. Editing an existing code does not consume a new slot, so
     * `create-qr-code` deliberately does not apply.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('qrCode'));
    }

    /**
     * A plain 403. Editing is refused for ownership or for a lapsed plan, and neither
     * is a quota story — the parent's upgrade redirect would be a lie in both cases.
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException;
    }
}
