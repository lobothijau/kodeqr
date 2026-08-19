<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('redirect')->group(base_path('routes/redirect.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare terminates TLS and rewrites the client address. Without this,
        // every scan appears to come from an edge IP: the redirect throttle would
        // bucket a whole city into one limit, and constraint 3's ip_hash would hash
        // Cloudflare instead of the scanner.
        // Headers are enumerated deliberately. The default set includes
        // X-Forwarded-Host, and trusting that from any client lets an attacker point
        // a password-reset link at their own domain.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Empty by decision, and it must stay that way. `web` would put session,
        // cookies and CSRF on the critical path of every scan; a throttle here cost
        // eight Redis round-trips to the resolution's one, and refused nothing a
        // flood would not have already paid for. Rate limiting belongs at the
        // Cloudflare edge, which can drop traffic before it reaches PHP (I1).
        $middleware->group('redirect', []);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Invariant I2, enforced outside the controller as well as inside it: the
        // throttle and the router both run before __invoke, so a cache outage or a
        // garbage path would otherwise reach the scanner as a framework error page.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('x/*')) {
                return null;
            }

            return $e instanceof NotFoundHttpException
                ? response()->view('redirect.not-found', [], 404)->header('Cache-Control', 'no-store')
                : response()->view('redirect.unavailable', [], 200)->header('Cache-Control', 'no-store');
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
