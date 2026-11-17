<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins only use the analytics area, so client pages (generate, history)
 * send them there instead. Generation details stay reachable for admins.
 */
class RedirectAdminToAnalytics
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Admins cannot create generations.');
        }

        return to_route('admin.generations.index');
    }
}
