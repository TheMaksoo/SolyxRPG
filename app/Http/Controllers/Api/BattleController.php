<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Monster;
use App\Models\Zone;
use App\Services\CombatService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BattleController extends Controller
{
    public function __construct(private CombatService $combat) {}

    public function enemies(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $zoneId = $request->query('zone', $character->current_zone_id);

        $monsters = Monster::query()
            ->where('enabled', true)
            ->when($zoneId, fn ($q) => $q->where('zone_id', $zoneId))
            ->where('min_level', '<=', $character->level + 10)
            ->orderBy('min_level')
            ->get();

        return response()->json(['enemies' => $monsters]);
    }

    public function start(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['monster_id' => ['required', 'exists:monsters,id']]);
        $monster = Monster::findOrFail($data['monster_id']);

        // one active battle at a time
        Battle::where('character_id', $character->id)->where('status', 'active')->update(['status' => 'lost']);

        $battle = $this->combat->start($character, $monster);

        return response()->json(['battle' => $battle->load('monster')]);
    }

    public function show(Request $request, Battle $battle)
    {
        $this->authorizeBattle($request, $battle);

        return response()->json(['battle' => $battle->load('monster')]);
    }

    public function action(Request $request, Battle $battle)
    {
        $this->authorizeBattle($request, $battle);

        $data = $request->validate([
            'type' => ['required', Rule::in(['attack', 'skill', 'item'])],
            'skill_id' => ['nullable', 'exists:skills,id'],
            'item_id' => ['nullable', 'exists:items,id'],
        ]);

        $result = $this->combat->act(
            $battle,
            $request->user()->character,
            $data['type'],
            $data['skill_id'] ?? null,
            $data['item_id'] ?? null,
        );

        return response()->json($result);
    }

    private function authorizeBattle(Request $request, Battle $battle): void
    {
        abort_unless($battle->character_id === $request->user()->character?->id, 403);
    }
}
