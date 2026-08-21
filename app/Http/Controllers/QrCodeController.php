<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\QrCodes\CreateQrCode;
use App\Enums\QrCodeStatus;
use App\Enums\QrCodeType;
use App\Http\Requests\StoreQrCodeRequest;
use App\Http\Requests\UpdateQrCodeRequest;
use App\Models\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class QrCodeController extends Controller
{
    public function index(Request $request): Response
    {
        $entitlements = $request->user()->entitlements();

        return Inertia::render('qr-codes/Index', [
            // Scoped to the owner, never filtered by a request parameter: an index
            // that takes a user id is an IDOR waiting for somebody to guess one.
            'codes' => $request->user()->qrCodes()
                ->latest()
                ->get(['id', 'slug', 'type', 'status', 'scan_count', 'destination', 'last_scanned_at']),
            'quota' => [
                'used' => $request->user()->qrCodes()->count(),
                'limit' => $entitlements->limit('max_codes'),
            ],
            'canCreate' => $entitlements->canCreateQrCode(),
            'canEdit' => $entitlements->can('can_edit'),
            // Passed as a prop rather than read off the shared page object: the scan
            // URL is the one string in the product that ends up on paper, and it must
            // come from config, not from whatever Host the browser happened to send.
            'scanBaseUrl' => rtrim((string) config('app.url'), '/'),
            // Labels come from lang/id, not from the enum: the Vue layer has no
            // translator, so anything it renders raw is English by default
            // (constraint 10).
            'statusLabels' => collect(QrCodeStatus::cases())
                ->mapWithKeys(fn (QrCodeStatus $status): array => [$status->value => __('qr.status_label.'.$status->value)])
                ->all(),
            'quotaReached' => $request->session()->get('quotaReached'),
            // Read back explicitly. `->with('status', ...)` puts a string in the
            // session and stops there — every success and refusal message in this
            // controller was invisible, including the one telling an owner why the
            // pause button did nothing on a blocked code.
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        // Checked before the form is shown as well as before the write. The form is
        // the courtesy; store() is the enforcement.
        if (Gate::denies('create-qr-code')) {
            return $this->quotaReached();
        }

        return Inertia::render('qr-codes/Create', ['types' => $this->types()]);
    }

    public function store(StoreQrCodeRequest $request, CreateQrCode $create): RedirectResponse
    {
        // No gate check here: StoreQrCodeRequest::authorize() already ran it, before
        // validation, so nothing over quota reaches this line. A second check would
        // read as defence in depth while actually being unreachable code that the
        // next person maintains for no reason.
        $code = $create($request->user(), $request->destination());

        return to_route('qr-codes.index')->with('status', __('qr.created', ['slug' => $code->slug]));
    }

    public function edit(Request $request, QrCode $qrCode): Response
    {
        Gate::authorize('update', $qrCode);

        return Inertia::render('qr-codes/Edit', [
            'code' => $qrCode->only(['id', 'slug', 'type', 'status', 'destination', 'scan_count']),
            'types' => $this->types(),
        ]);
    }

    public function update(UpdateQrCodeRequest $request, QrCode $qrCode): RedirectResponse
    {
        Gate::authorize('update', $qrCode);

        // The observer re-renders dest_url and forgets the cache, which is what makes
        // "edit the destination, re-scan the printed paper, land somewhere new in
        // seconds" true. `status` is not touched here — it belongs to the billing and
        // quota state machines (constraint 8) and to togglePause below.
        $qrCode->fill($request->destination())->save();

        return to_route('qr-codes.index')->with('status', __('qr.updated'));
    }

    public function togglePause(Request $request, QrCode $qrCode): RedirectResponse
    {
        Gate::authorize('update', $qrCode);

        // Only between active and paused. A blocked or over_quota code is not the
        // owner's to resume — one is an abuse decision, the other a billing one.
        if (! in_array($qrCode->status, [QrCodeStatus::Active, QrCodeStatus::Paused], true)) {
            return back()->with('status', __('qr.not_pausable'));
        }

        $qrCode->status = $qrCode->status === QrCodeStatus::Active
            ? QrCodeStatus::Paused
            : QrCodeStatus::Active;
        $qrCode->save();

        return back()->with('status', __('qr.status.'.$qrCode->status->value));
    }

    public function destroy(Request $request, QrCode $qrCode): RedirectResponse
    {
        Gate::authorize('delete', $qrCode);

        // Soft, always. The paper outlives the row: a hard delete would 404 a code
        // somebody has already printed, and constraint 8 says a scanner always gets a
        // branded page. It also frees the quota slot without freeing the slug.
        $qrCode->delete();

        return to_route('qr-codes.index')->with('status', __('qr.deleted'));
    }

    private function quotaReached(): RedirectResponse
    {
        return to_route('qr-codes.index')
            ->with('quotaReached', StoreQrCodeRequest::quotaPayload(request()->user()));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function types(): array
    {
        return array_map(
            fn (QrCodeType $type): array => ['value' => $type->value, 'label' => __('qr.type.'.$type->value)],
            [QrCodeType::Url, QrCodeType::Whatsapp],
        );
    }
}
