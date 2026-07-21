<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Dungeon;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class StatsController extends Controller
{
    /** Number of playable base classes — a fixed design constant, not DB-backed (see CharacterCreatePage.vue). */
    private const PLAYABLE_CLASSES = 4;

    /** A character touched within this window counts as "online" — an activity proxy, not real presence
     * tracking, since nothing in this app maintains session heartbeats. */
    private const ONLINE_WINDOW_MINUTES = 15;

    /** Public, unauthenticated landing-page stats. Only ever return aggregate counts here — nothing
     * per-user or otherwise identifiable, since this route has no auth gate. This runs 4 aggregate
     * queries (2 of them a full range-scan over characters.updated_at) and is hit on every GameLayout.vue
     * mount plus the anonymous landing page, i.e. on essentially every page load. The PreventApiCaching
     * middleware still stamps every response no-store (this data is identical for every visitor, but the
     * header is global and this pass doesn't touch it), so this is a short server-side cache instead —
     * a 20s staleness window is invisible on an "X players online" counter but saves the vast majority of
     * these page-load-triggered query bursts from ever reaching the DB. */
    public function public(): JsonResponse
    {
        $data = Cache::remember('stats:public', 20, fn () => [
            'players_online' => Character::where('updated_at', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES))->count(),
            'players_active_hour' => Character::where('updated_at', '>=', now()->subHour())->count(),
            'adventurers' => Character::count(),
            'zones_dungeons' => Zone::where('enabled', true)->count() + Dungeon::count(),
            'classes' => self::PLAYABLE_CLASSES,
        ]);

        return response()->json($data);
    }
}
