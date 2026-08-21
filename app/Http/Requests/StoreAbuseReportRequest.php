<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AbuseReason;
use App\Services\SlugGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately incurious about whether the reported code exists.
 *
 * Every rule here is about the SHAPE of the input and never about the state of the
 * database, because a rule that could fail only for an unknown slug turns this form
 * into an oracle: submit, read the error, learn which slugs are live. The whole
 * endpoint is worth less than that leak would cost.
 */
class StoreAbuseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Slug or full /x URL — both are things a person can copy off a phone.
            'report' => ['required', 'string', 'max:2048'],
            'reason' => ['required', Rule::enum(AbuseReason::class)],
            'reporter_email' => ['nullable', 'email:rfc', 'max:255'],
            // `subjek` is the honeypot and is deliberately ABSENT from this list. A
            // `prohibited` rule would answer it with a 422 naming the field, which
            // tells the author which one caught them and costs them one edit. The
            // controller drops those submissions into a success page instead: the bot
            // reports back that it worked, and keeps spending on a form we ignore.
        ];
    }

    /**
     * The slug the report names, if it names one at all.
     *
     * Accepts a bare slug, a full https://kodeqr.com/x/{slug}, and the mangled
     * middle ground people actually paste: a trailing slash, a query string a
     * scanner app appended, surrounding whitespace.
     */
    public function reportedSlug(): ?string
    {
        $report = trim((string) $this->string('report'));

        if (preg_match('~^'.SlugGenerator::PATTERN.'$~', $report) === 1) {
            return $report;
        }

        // The scheme is supplied before parsing, not after, and that ordering is the
        // whole guard: `parse_url('attacker.example/x/Ab3xK9')` reports NO host and
        // puts the authority in `path`, so a host check run first sees nothing to
        // reject and the slug is credited to us. Same shape as the hole the renderer
        // had (M1-T6), for the same reason.
        $candidate = match (true) {
            // A bare path is ours by construction — it cannot name another host.
            str_starts_with($report, '/') => 'https://'.$this->appHost().$report,
            parse_url($report, PHP_URL_SCHEME) === null => 'https://'.$report,
            default => $report,
        };

        $parts = parse_url($candidate);

        if (! is_array($parts)) {
            return null;
        }

        // Only our own host. `https://attacker.example/x/Ab3xK9` is not a report of
        // OUR Ab3xK9 — reading it as one lets anybody raise a flag against any live
        // code with a URL that never existed, and put that code's destination in an
        // operator's inbox. It is still kept, as the free text it actually is.
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $host = str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;

        if ($host !== $this->appHost()) {
            return null;
        }

        return preg_match('~^/x/('.SlugGenerator::PATTERN.')/?$~', (string) ($parts['path'] ?? ''), $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * `www.` tolerated because people copy what the address bar shows them.
     */
    private function appHost(): string
    {
        $host = mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
    }
}
