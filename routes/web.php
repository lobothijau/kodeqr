<?php

use App\Http\Controllers\AbuseReportController;
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
});

require __DIR__.'/settings.php';
