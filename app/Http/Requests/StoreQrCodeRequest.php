<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Plan;
use App\Enums\QrCodeType;
use App\Models\User;
use App\Rules\SafeDestination;
use App\Services\DestinationRenderer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Shared by create and edit (UpdateQrCodeRequest extends this).
 *
 * Sharing them is the point, not a convenience: constraint 5 says every destination
 * passes the threat check on create AND edit, and the classic way that breaks is two
 * request classes drifting apart until only one carries SafeDestination. One list of
 * rules cannot drift from itself.
 */
class StoreQrCodeRequest extends FormRequest
{
    /**
     * Authorization runs BEFORE validation, and on this request that ordering is the
     * point rather than a detail.
     *
     * With the check left in the controller, a request from someone over their plan
     * limit was validated first — which means SafeDestination, which means a live DNS
     * round-trip to a threat resolver, paid for by anyone who can POST this form. The
     * refusal is free; the validation is not.
     */
    public function authorize(): bool
    {
        return Gate::allows('create-qr-code');
    }

    /**
     * A refusal an owner can act on, rather than a bare 403.
     *
     * Authorising in the request is what keeps a threat lookup from being spent on
     * somebody who is over their limit — but the default failure is Laravel's generic
     * forbidden page, which never reaches the controller and so never carries the
     * `upgrade_to` payload the constitution asks for on a plan-gated error. This puts
     * it back without giving up the ordering.
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            to_route('qr-codes.index')->with('quotaReached', self::quotaPayload($this->user()))
        );
    }

    /**
     * One builder for the refusal, shared with the controller's GET path.
     *
     * They were assembled separately and disagreed: this one offered the next tier
     * while the other offered the CURRENT plan, so the same owner was told to upgrade
     * to Regular on submit and to free on the form — the kind of thing that surfaces
     * only in a screenshot from a customer.
     *
     * @return array{message: string, upgrade_to: string|null}
     */
    public static function quotaPayload(User $user): array
    {
        $entitlements = $user->entitlements();
        $plan = $entitlements->plan();

        return [
            // A lapsed owner has not run out of anything — they have stopped paying,
            // and their codes still redirect. Telling them they have used all 0 of
            // their codes is nonsense addressed to somebody we want back.
            'message' => $plan === Plan::Lapsed
                ? __('qr.lapsed')
                : __('qr.quota_reached', ['limit' => (string) $entitlements->limit('max_codes')]),
            'upgrade_to' => $plan->upgradeTarget()?->value,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $type = $this->enum('type', QrCodeType::class);

        return [
            // Only the two types M2-T1 builds. DestinationRenderer throws on the rest,
            // which would be a 500 rather than a message the owner can act on.
            'type' => ['required', Rule::in([QrCodeType::Url->value, QrCodeType::Whatsapp->value])],

            'url' => [
                Rule::requiredIf($type === QrCodeType::Url),
                'exclude_unless:type,'.QrCodeType::Url->value,
                'string',
                'max:2048',
                // `url:http,https` before the threat check, so an obviously broken
                // address is answered instantly instead of costing a DNS round-trip.
                'url:http,https',
                ...$this->threatCheck(),
            ],

            'phone' => [
                Rule::requiredIf($type === QrCodeType::Whatsapp),
                'exclude_unless:type,'.QrCodeType::Whatsapp->value,
                'string',
                'max:20',
            ],
            'text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Resolved from the container, because SafeDestination takes a ThreatCheck.
     *
     * @return array<int, ValidationRule>
     */
    private function threatCheck(): array
    {
        return [app(SafeDestination::class)];
    }

    /**
     * Ask the renderer whether it can actually render this, rather than trying to
     * restate its rules here.
     *
     * The rules are real and specific — nine digits minimum after normalisation, no
     * userinfo, no backslash, no percent-escape in the host, nothing pointing back at
     * /x/ — and any copy of them in a `regex:` would drift the first time the renderer
     * tightened. Left unchecked they surface as a 500 from a model observer, because
     * the renderer throws where validation would have answered: `phone: "abc"` passes
     * `string|max:20` and then explodes on save, and `https://a.test@evil.test/` passes
     * Laravel's `url` and then explodes too. The owner sees a server error for what is
     * really a typo.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Only when the cheap rules already passed: no point asking the renderer
            // about a field that is simply missing.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $payload = $this->destination();
            $field = $payload['type'] === QrCodeType::Whatsapp ? 'phone' : 'url';

            try {
                app(DestinationRenderer::class)->render($payload['type'], $payload['destination']);
            } catch (InvalidArgumentException) {
                // The renderer's own messages are developer English. The owner gets a
                // Bahasa one naming the field they can fix.
                $validator->errors()->add($field, __('qr.unrenderable.'.$field));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'url' => __('qr.field.url'),
            'phone' => __('qr.field.phone'),
            'text' => __('qr.field.text'),
            'type' => __('qr.field.type'),
        ];
    }

    /**
     * The shape DestinationRenderer expects, with the type kept alongside it.
     *
     * @return array{type: QrCodeType, destination: array<string, mixed>}
     */
    public function destination(): array
    {
        $type = $this->enum('type', QrCodeType::class) ?? QrCodeType::Url;

        return [
            'type' => $type,
            'destination' => $type === QrCodeType::Whatsapp
                ? ['phone' => (string) $this->string('phone'), 'text' => (string) $this->string('text')]
                : ['url' => (string) $this->string('url')],
        ];
    }
}
