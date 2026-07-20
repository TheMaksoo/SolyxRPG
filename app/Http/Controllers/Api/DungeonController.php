<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Dungeon;
use App\Models\DungeonRun;
use App\Models\FeatureFlag;
use App\Services\DungeonService;
use Illuminate\Http\Request;

class DungeonController extends Controller
{
    public function __construct(private DungeonService $dungeons) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('dungeons', $request->user()), 403, 'Dungeons are not currently available.');

        $character = $request->user()->character;

        $activeRuns = $character
            ? DungeonRun::where('character_id', $character->id)->where('status', 'active')->get()->keyBy('dungeon_id')
            : collect();

        $dungeons = Dungeon::where('enabled', true)->with('bossMonster')->get()->map(fn (Dungeon $d) => [
            'dungeon' => $d,
            'unlocked' => $character && $character->level >= $d->min_level,
            'active_run' => $activeRuns->get($d->id),
        ]);

        $maxAttempts = 3 + $request->user()->vipDungeonBonusAttempts();
        $attemptsUsed = ($character && $character->dungeon_attempts_reset_at && $character->dungeon_attempts_reset_at->isFuture())
            ? $character->dungeon_attempts_used
            : 0;

        return response()->json([
            'dungeons' => $dungeons,
            'dungeon_attempts_used' => $attemptsUsed,
            'dungeon_attempts_max' => $maxAttempts,
        ]);
    }

    /** Starts (or resumes) a multi-stage instance run culminating in the dungeon's boss. */
    public function enter(Request $request, Dungeon $dungeon)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->level < $dungeon->min_level) {
            return response()->json(['message' => "Requires level {$dungeon->min_level}."], 422);
        }
        abort_unless($dungeon->bossMonster, 422, 'Dungeon has no boss configured.');

        $character->applyPassiveRegen();

        $hasRunForThisDungeon = DungeonRun::where('character_id', $character->id)
            ->where('dungeon_id', $dungeon->id)
            ->where('status', 'active')
            ->exists();

        if (! $hasRunForThisDungeon && Battle::where('character_id', $character->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'You have a battle in progress. Resume it or flee first.'], 422);
        }

        if (! $hasRunForThisDungeon) {
            $this->consumeDungeonAttempt($character);
        }

        $result = $this->dungeons->enter($character, $dungeon);

        return response()->json([
            'battle' => $result['battle'],
            'dungeon' => $dungeon,
            'dungeon_run' => $result['run'],
        ]);
    }

    /** Resets the daily attempt counter if the reset window has lapsed, enforces the VIP-scaled daily cap,
     * then consumes one attempt. Aborts with a 422 if the player is out of raid attempts for today. Only
     * called when starting a fresh run — resuming an already-active run doesn't cost another attempt. */
    private function consumeDungeonAttempt(Character $character): void
    {
        if (! $character->dungeon_attempts_reset_at || $character->dungeon_attempts_reset_at->isPast()) {
            $character->dungeon_attempts_used = 0;
            $character->dungeon_attempts_reset_at = now()->endOfDay();
        }

        $max = 3 + $character->user->vipDungeonBonusAttempts();
        abort_if($character->dungeon_attempts_used >= $max, 422, 'No dungeon raids remaining today. Resets at midnight.');

        $character->dungeon_attempts_used++;
        $character->save();
    }
}
