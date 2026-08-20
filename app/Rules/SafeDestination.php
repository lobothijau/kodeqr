<?php

declare(strict_types=1);

namespace App\Rules;

use App\Jobs\RecheckDestination;
use App\Services\ThreatCheck;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Constraint 5 at the edge of the application: no destination reaches the database
 * without this having answered.
 *
 * M2-T1 attaches it to the create AND edit form requests. Attaching it to create
 * only is the classic hole — an attacker saves something clean, then edits it to a
 * phishing page — so the edit path carries its own test in M2-T1's acceptance.
 */
final class SafeDestination implements ValidationRule
{
    public function __construct(private readonly ThreatCheck $threats) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $verdict = $this->threats->check($value);

        if ($verdict->blocked) {
            $this->threats->flag($value, $verdict);

            // The provider is named in the message: this is their finding, relayed.
            $fail('validation.safe_destination')->translate([
                'layanan' => (string) config('services.threat_check.name'),
            ]);

            return;
        }

        if (! $verdict->checked) {
            // The check could not run, and the save is going through anyway. The job
            // finds the code by its stored destination rather than by id, because on
            // create there is no id yet — validation runs before the row exists, and
            // a recheck that could only flag a URL would close nothing.
            RecheckDestination::dispatch($value)->delay(now()->addMinutes(10));
        }
    }
}
