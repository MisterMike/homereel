<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->routeIs('logout', 'filament.*.auth.logout')) {
            abort_unless($request->user()->is_active && $request->user()->invitation_token === null, 403);
        }

        return $next($request);
    }
}
