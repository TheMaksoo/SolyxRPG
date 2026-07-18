<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dungeon;
use App\Services\CombatService;
use Illuminate\Http\Request;

class DungeonController extends Controller
{
    public function __construct(private CombatService $combat) {}

    public function index(Request $request)
    {
        $character = $request->user()->character;

        $dungeons = Dungeon::where('enabled', true)->with('bossMonster')->get()->map(fn (Dungeon $d) => [
            'dungeon' => $d,
            'unlocked' => $character && $character->level >= $d->min_level,
        ]);

        return response()->json(['dungeons' => $dungeons]);
    }

    /** Starts a boss battle against the dungeon's boss (solo simplification of the raid party_size). */
    public function enter(Request $request, Dungeon $dungeon)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->level < $dungeon->min_level) {
            return response()->json(['message' => "Requires level {$dungeon->min_level}."], 422);
        }
        abort_unless($dungeon->bossMonster, 422, 'Dungeon has no boss configured.');

        $battle = $this->combat->start($character, $dungeon->bossMonster);

        return response()->json(['battle' => $battle->load('monster'), 'dungeon' => $dungeon]);
    }
}
