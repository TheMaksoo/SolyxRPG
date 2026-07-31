<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackCharacterActivity
{
    /** Caps how much elapsed time a single request can add to playtime_seconds — without this, a tab
     * left open for hours (or days) before the next request would credit all of that dead time as
     * played time in one jump. 120s comfortably covers normal request gaps during active play (ticks,
     * page navigation, polling) without rewarding idle tabs. */
    private const MAX_GAP_SECONDS = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $character = $request->user()?->character;

        if ($character) {
            $now = now();
            $elapsed = $character->last_active_at ? $now->getTimestamp() - $character->last_active_at->getTimestamp() : 0;
            $character->increment('playtime_seconds', max(0, min($elapsed, self::MAX_GAP_SECONDS)), ['last_active_at' => $now]);
        }

        return $next($request);
    }
}
