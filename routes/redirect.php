<?php

declare(strict_types=1);

use App\Http\Controllers\RedirectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Redirect routes
|--------------------------------------------------------------------------
|
| Loaded in bootstrap/app.php under the `redirect` middleware group — throttle
| and nothing else. Never add `web` here: session, cookies and CSRF would put
| storage on the critical path of every scan and break invariant I1 silently.
|
| The slug constraint is the project's 54-character alphabet (no 0 1 I L O i l o),
| so a fuzzed path is rejected by the router without touching Redis or MySQL.
|
| Length is 6 to 8, not 6: M1-T2 falls back to a 7-char slug after five collisions
| and the column is varchar(8). A {6} constraint would 404 those codes forever,
| after they had already been printed.
|
*/

Route::get('/x/{slug}', RedirectController::class)
    ->where('slug', '[2-9A-HJKMNP-Za-hjkmnp-z]{6,8}')
    ->name('redirect.show');
