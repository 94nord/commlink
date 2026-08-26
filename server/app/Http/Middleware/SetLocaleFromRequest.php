<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which language this request's response is written in.
 *
 * The frontend sends its active language on every call (`web/src/api/client.ts`)
 * rather than this reading a stored preference, because the preference lives
 * only in the browser's localStorage (no per-user column) — the request
 * itself is the only place this app ever learns it.
 */
class SetLocaleFromRequest
{
    private const SUPPORTED = ['en', 'da'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language');

        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
