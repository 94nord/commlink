<?php

namespace App\Support;

/**
 * Where the SPA lives.
 *
 * Anything the app sends someone — a password reset mail, a photo upload
 * link in a text — has to point at web/ rather than at the API, and both
 * need the same answer. Kept in one place so the two cannot drift apart:
 * the version that drifts sends people to a page that is not there.
 */
final class FrontendUrl
{
    public static function base(): string
    {
        return rtrim(
            (string) config('cors.allowed_origins.0', env('FRONTEND_URL', 'http://localhost:5173')),
            '/',
        );
    }

    public static function to(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }
}
