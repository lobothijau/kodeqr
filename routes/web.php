<?php

use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\QrCodeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

/*
 * The public abuse report (M1-T7). Plain Blade, not Inertia: it is read by the same
 * person the redirect status pages are read by — on a phone, on cellular, sometimes
 * in an in-app browser, sometimes minutes after being defrauded — and a report that
 * depends on a JS bundle is a report lost whenever the bundle is.
 *
 * The throttle is on the write only. A GET is a static page and Cloudflare's problem;
 * putting the two on one bucket would mean a mistyped submission costs the reporter
 * the reload they need to correct it.
 */
Route::get('laporkan', [AbuseReportController::class, 'show'])->name('abuse.report.show');
Route::post('laporkan', [AbuseReportController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('abuse.report.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    /*
     * The builder. Every route that names a code is model-bound and then
     * policy-checked, so a request for somebody else's ULID is refused rather than
     * scoped away silently — a 403 is the honest answer, and the IDOR test asserts it.
     *
     * `slug` is not a route key anywhere here: the slug is the public identity on
     * printed paper and the id is the private one, and mixing them would let anyone
     * holding a scanned code address its owner's management endpoints.
     */
    Route::get('kode', [QrCodeController::class, 'index'])->name('qr-codes.index');
    Route::get('kode/baru', [QrCodeController::class, 'create'])->name('qr-codes.create');
    Route::post('kode', [QrCodeController::class, 'store'])->name('qr-codes.store');
    Route::get('kode/{qrCode}/ubah', [QrCodeController::class, 'edit'])->name('qr-codes.edit');
    Route::patch('kode/{qrCode}', [QrCodeController::class, 'update'])->name('qr-codes.update');
    Route::post('kode/{qrCode}/jeda', [QrCodeController::class, 'togglePause'])->name('qr-codes.pause');
    Route::delete('kode/{qrCode}', [QrCodeController::class, 'destroy'])->name('qr-codes.destroy');
});

require __DIR__.'/settings.php';
