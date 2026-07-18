<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsGm
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isGm(), 403, 'GM access required.');

        return $next($request);
    }
}
