<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AbuseReason;
use App\Enums\AbuseSource;
use App\Http\Requests\StoreAbuseReportRequest;
use App\Mail\AbuseReported;
use App\Models\AbuseFlag;
use App\Models\QrCode;
use App\Services\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * The public "this code is malicious" form.
 *
 * Read by the same person the redirect pages are read by — on a phone, on cellular,
 * possibly inside an in-app browser, and possibly a minute after being defrauded. It
 * is plain Blade with an inline stylesheet for that reason: an abuse report that
 * depends on a JS bundle is an abuse report lost whenever the bundle is.
 *
 * The single rule the whole class is built around: THE RESPONSE MUST NOT DEPEND ON
 * WHETHER THE REPORTED CODE EXISTS. Same status, same body, same redirect, same
 * flash, whether the slug is live, deleted, or invented. Otherwise the form is a
 * cheap oracle for enumerating live slugs, which is worth more to an attacker than
 * this endpoint is worth to us.
 */
class AbuseReportController extends Controller
{
    /**
     * `?kode=Ab3xK9` prefills the field.
     *
     * Nobody types a slug. The person who needs this form has just scanned the code
     * and is looking at a page we rendered, so we already know which code it is — and
     * in an in-app browser there is frequently no address bar for them to copy from
     * even if they wanted to. Any link we put on a scanner-facing page carries the
     * slug, and the reporter only has to pick a reason.
     *
     * Validated against the slug pattern rather than echoed: it is a query parameter
     * on a public page, so it is attacker-controlled, and prefilling arbitrary text
     * would put whatever they like into a field an operator later reads.
     */
    public function show(Request $request): View
    {
        $kode = (string) $request->string('kode');

        return view('abuse.report', [
            'reasons' => AbuseReason::cases(),
            'prefill' => preg_match('~^'.SlugGenerator::PATTERN.'$~', $kode) === 1 ? $kode : '',
        ]);
    }

    public function store(StoreAbuseReportRequest $request): RedirectResponse
    {
        $slug = $request->reportedSlug();

        // Looked up unconditionally, and the result is used only to fill a column.
        // Branching the RESPONSE on it is the whole thing this endpoint must not do.
        $code = $slug === null ? null : QrCode::withTrashed()->where('slug', $slug)->first();

        if (! $this->isBot($request)) {
            $flag = AbuseFlag::create([
                'qr_code_id' => $code?->id,
                // What the reporter saw, not where it pointed. The destination is
                // reachable through qr_code_id and is not what they were looking at.
                'url' => $slug === null
                    ? mb_substr(trim((string) $request->string('report')), 0, 2048)
                    : route('redirect.show', ['slug' => $slug]),
                'source' => AbuseSource::Report,
                'reason' => $request->enum('reason', AbuseReason::class),
                'reporter_email' => $request->string('reporter_email')->value() ?: null,
            ]);

            // Queued: a mail server that is slow, or down, must not be able to hold
            // the report form open or lose a submission that is already persisted.
            Mail::to(config('mail.abuse.address'))->queue(new AbuseReported($flag));
        }

        return to_route('abuse.report.show')->with('status', 'reported');
    }

    /**
     * The honeypot.
     *
     * A human never sees the field: it is off-screen, `tabindex="-1"` and
     * `aria-hidden`, so anything in it came from something filling inputs by name.
     * Those submissions get the same page, the same status and the same flash a real
     * report gets, and nothing is written. The alternative — a validation error —
     * would name the field that caught them, which is one edit away from defeating
     * it. Silence is the only version of this that keeps working.
     */
    private function isBot(Request $request): bool
    {
        $honeypot = $request->input('subjek');

        // `input()` and an explicit array branch, not `string()`: the field is not in
        // the validation rules, so `subjek[]=x` arrives as an array and `string()`
        // raises "Array to string conversion" — a 500, from the one path whose entire
        // value is that it says nothing at all.
        return is_array($honeypot)
            ? $honeypot !== []
            : trim((string) $honeypot) !== '';
    }
}
