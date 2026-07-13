<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for admin-only surfaces (Staff management, Corporation settings). The
 * admin flag lives on the user's linked staff member (D2 superseded: roles
 * now exist).
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
