<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\Zone;
use App\Services\QuestService;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function __construct(private QuestService $quests = new QuestService()) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('world_map', $request->user()), 403, 'The World Map is not currently available.');

        $character = $request->user()->character;
        $frontier = $character ? Zone::frontierFor($character) : null;

        $zones = Zone::where('enabled', true)->orderBy('sort_order')->get()->map(function (Zone $zone) use ($character, $frontier) {
            $unlocked = $character && $character->level >= $zone->min_level && ! $zone->locked;

            return [
                'zone' => $zone,
                'unlocked' => $unlocked,
                'reward_penalty_pct' => $character && $unlocked ? (int) round(Zone::frontierPenaltyPct($character, $zone)) : 0,
                'pet_food_bonus_pct' => (int) round(Zone::petFoodBonusPct($zone->danger)),
                'is_frontier' => $frontier && $frontier->id === $zone->id,
            ];
        });

        return response()->json(['zones' => $zones]);
    }

    public function travel(Request $request, Zone $zone)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($zone->locked) {
            return response()->json(['message' => 'This zone is locked.'], 422);
        }
        if ($character->level < $zone->min_level) {
            return response()->json(['message' => "Requires level {$zone->min_level}."], 422);
        }

        $character->update(['current_zone_id' => $zone->id]);
        $this->quests->progress($character, 'zones_visited');

        return response()->json(['character' => $character->fresh('zone')]);
    }
}
