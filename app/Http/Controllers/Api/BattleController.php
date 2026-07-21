<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\DungeonRun;
use App\Models\FeatureFlag;
use App\Models\Monster;
use App\Services\CombatService;
use App\Services\DungeonService;
use App\Services\GradeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class BattleController extends Controller
{
    public function __construct(
        private CombatService $combat,
        private DungeonService $dungeons,
        private GradeService $grades,
    ) {}

    /** The character's in-progress battle, if any — used to resume after a disconnect/reload. */
    public function active(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $battle = Battle::where('character_id', $character->id)->where('status', 'active')->with(['monster', 'battleMonsters.monster'])->first();
        $dungeonRun = null;
        if ($battle) {
            $this->combat->regenInBattle($battle, $character);
            if (Schema::hasTable('dungeon_runs')) {
                $dungeonRun = DungeonRun::where('battle_id', $battle->id)->where('status', 'active')->first();
            }
        }

        return response()->json(['battle' => $battle, 'dungeon_run' => $dungeonRun]);
    }

    /** Walks into a fresh, randomly-graded encounter in the character's current zone — the only way to start a fight. */
    public function walk(Request $request)
    {
        // Gated on starting a new fight only — resuming/acting on an already-active battle (active/show/action)
        // stays reachable even if a GM flips this off, so nobody gets soft-locked mid-combat.
        abort_unless(FeatureFlag::gate('battle', $request->user()), 403, 'Battle is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        if (Battle::where('character_id', $character->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'You have a battle in progress. Resume it or flee first.'], 422);
        }

        $character->applyPassiveRegen();

        $zoneId = $character->current_zone_id;
        $monster = Monster::query()
            ->where('enabled', true)
            ->where('is_boss', false)
            ->when($zoneId, fn ($q) => $q->where('zone_id', $zoneId))
            // No forward tolerance: a monster's min_level is tuned as "safe from this level on" (see
            // CharacterController::store's starter-stat comment), so letting the pool reach ahead of the
            // player's actual level — this used to allow +10 — could hand a level 1 character a monster
            // built for level 10 players before they've earned any attribute points or gear to cope with it.
            ->where('min_level', '<=', $character->level)
            ->inRandomOrder()
            ->first();

        if (! $monster) {
            return response()->json(['message' => 'No enemies to walk into yet — try a zone that matches your level.'], 422);
        }

        $grade = $this->grades->roll($character->level);
        $battle = $this->combat->start($character, $monster, $grade);
        $character->update(['last_action' => "Fighting {$monster->name}"]);

        return response()->json(['battle' => $battle->load(['monster', 'battleMonsters.monster']), 'grade' => $this->grades->meta($grade)]);
    }

    public function show(Request $request, Battle $battle)
    {
        $this->authorizeBattle($request, $battle);

        $this->combat->regenInBattle($battle, $request->user()->character);

        return response()->json(['battle' => $battle->load(['monster', 'battleMonsters.monster'])]);
    }

    public function action(Request $request, Battle $battle)
    {
        $this->authorizeBattle($request, $battle);

        $data = $request->validate([
            'type' => ['required', Rule::in(['attack', 'skill', 'item', 'flee'])],
            'skill_id' => ['nullable', 'exists:skills,id'],
            'item_id' => ['nullable', 'exists:items,id'],
            'target_monster_id' => ['nullable', 'exists:battle_monsters,id'],
        ]);

        $character = $request->user()->character;

        if ($data['type'] === 'flee') {
            $result = $this->combat->flee($battle, $character);
            $result['dungeon_run'] = Schema::hasTable('dungeon_runs')
                ? $this->dungeons->onBattleResolved($battle, $character, 'fled')
                : null;

            return response()->json($result);
        }

        $result = $this->combat->act(
            $battle,
            $character,
            $data['type'],
            $data['skill_id'] ?? null,
            $data['item_id'] ?? null,
            $data['target_monster_id'] ?? null,
        );

        $outcome = $result['result']['outcome'] ?? null;
        if ($outcome !== null && Schema::hasTable('dungeon_runs')) {
            $result['dungeon_run'] = $this->dungeons->onBattleResolved($battle, $character, $outcome);
        }

        return response()->json($result);
    }

    private function authorizeBattle(Request $request, Battle $battle): void
    {
        $user = $request->user();
        $activeCharacter = $user->character;

        if ($battle->character_id === $activeCharacter?->id) {
            return;
        }

        Log::warning('Battle ownership mismatch', [
            'session_id' => $request->session()->getId(),
            'auth_user_id' => $user->id,
            'auth_user_email' => $user->email,
            'active_character_id' => $activeCharacter?->id,
            'battle_id' => $battle->id,
            'battle_character_id' => $battle->character_id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        abort(403, 'This battle belongs to a different character.');
    }
}
