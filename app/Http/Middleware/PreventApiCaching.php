<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every API response carries account-specific state (character lists, active battle, gold, etc.) —
 * none of it should ever be cached by a browser or intermediary. Without this, a GET response fetched
 * before a migrate:fresh/reseed (or a character switch) could be replayed by the browser's own HTTP
 * cache afterward, showing stale IDs that no longer mean what they used to and tripping ownership
 * checks downstream. */
class PreventApiCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
