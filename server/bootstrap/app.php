<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
            SetLocaleFromRequest::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Test-only state endpoints (routes/testing.php) are called from the
        // Playwright request context, which has no CSRF token. Dev-only
        // endpoints (routes/dev.php) are called from devtools/, a separate
        // host-run app with no session either. Provider webhooks prove
        // themselves with a request signature instead.
        $middleware->validateCsrfTokens(except: [
            '_test/*',
            '_dev/*',
            'api/webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // findOrFail() is used pervasively across the JSON API and its default
        // message leaks the raw PHP model class name. Laravel's own
        // prepareException() rewrites ModelNotFoundException to
        // NotFoundHttpException before any render() callback runs, so the
        // callback has to target the rewritten type and check what caused
        // it — otherwise this never fires and the raw message leaks through.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (($request->expectsJson() || $request->is('api/*')) && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => __('errors.not_found')], 404);
            }
        });

        // Laravel's default "Unauthenticated." is a hardcoded literal, not
        // translated via the normal validation/auth lang files.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => __('errors.unauthenticated')], 401);
            }
        });
    })->create();
