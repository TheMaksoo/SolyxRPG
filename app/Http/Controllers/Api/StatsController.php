<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Dungeon;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    /** Number of playable base classes — a fixed design constant, not DB-backed (see CharacterCreatePage.vue). */
    private const PLAYABLE_CLASSES = 4;

    /** A character touched within this window counts as "online" — an activity proxy, not real presence
     * tracking, since nothing in this app maintains session heartbeats. */
    private const ONLINE_WINDOW_MINUTES = 15;

    /** Public, unauthenticated landing-page stats. Only ever return aggregate counts here — nothing
     * per-user or otherwise identifiable, since this route has no auth gate. */
    public function public(): JsonResponse
    {
        return response()->json([
            'players_online' => Character::where('updated_at', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES))->count(),
            'players_active_hour' => Character::where('updated_at', '>=', now()->subHour())->count(),
            'adventurers' => Character::count(),
            'zones_dungeons' => Zone::where('enabled', true)->count() + Dungeon::count(),
            'classes' => self::PLAYABLE_CLASSES,
        ]);
    }
}
