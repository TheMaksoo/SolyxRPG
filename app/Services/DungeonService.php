<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\Character;
use App\Models\Dungeon;
use App\Models\DungeonRun;
use App\Models\Monster;

class DungeonService
{
    private const STAGES_BY_DIFFICULTY = ['normal' => 1, 'hard' => 2, 'raid' => 3, 'mythic' => 4];

    public function __construct(private CombatService $combat) {}

    /** Starts a fresh run, or resumes an existing active one for this dungeon. */
    public function enter(Character $character, Dungeon $dungeon): array
    {
        $run = DungeonRun::where('character_id', $character->id)
            ->where('dungeon_id', $dungeon->id)
            ->where('status', 'active')
            ->first();

        if ($run) {
            return ['run' => $run, 'battle' => $run->battle->load('monster')];
        }

        $totalStages = self::STAGES_BY_DIFFICULTY[$dungeon->difficulty] ?? 1;
        $monster = $totalStages > 1 ? $this->pickStageMonster($dungeon, 1, $totalStages) : $dungeon->bossMonster;

        $battle = $this->combat->start($character, $monster);
        $run = DungeonRun::create([
            'character_id' => $character->id,
            'dungeon_id' => $dungeon->id,
            'battle_id' => $battle->id,
            'stage' => 1,
            'total_stages' => $totalStages,
            'status' => 'active',
        ]);

        return ['run' => $run, 'battle' => $battle->load('monster')];
    }

    /** Called after a battle tied to a dungeon run resolves. Advances to the next stage, or completes/abandons the run. */
    public function onBattleResolved(Battle $battle, Character $character, string $outcome): ?array
    {
        $run = DungeonRun::where('battle_id', $battle->id)->where('status', 'active')->first();
        if (! $run) {
            return null;
        }

        if ($outcome !== 'won') {
            $run->update(['status' => 'abandoned']);

            return ['stage' => $run->stage, 'total_stages' => $run->total_stages, 'completed' => false, 'abandoned' => true];
        }

        if ($run->stage >= $run->total_stages) {
            $run->update(['status' => 'completed']);

            $dungeon = $run->dungeon;
            $bonus = $dungeon->drops_json ?? [];
            if (! empty($bonus['gold'])) {
                $character->increment('gold', $bonus['gold']);
            }
            if (! empty($bonus['gems'])) {
                $character->increment('gems', $bonus['gems']);
            }

            return ['stage' => $run->stage, 'total_stages' => $run->total_stages, 'completed' => true, 'bonus' => $bonus];
        }

        $nextStage = $run->stage + 1;
        $monster = $nextStage >= $run->total_stages
            ? $run->dungeon->bossMonster
            : $this->pickStageMonster($run->dungeon, $nextStage, $run->total_stages);

        $nextBattle = $this->combat->start($character, $monster);
        $run->update(['stage' => $nextStage, 'battle_id' => $nextBattle->id]);

        return [
            'stage' => $nextStage,
            'total_stages' => $run->total_stages,
            'completed' => false,
            'next_battle' => $nextBattle->load('monster'),
        ];
    }

    /** Picks a weaker "trash" encounter for stages before the final boss stage. */
    private function pickStageMonster(Dungeon $dungeon, int $stage, int $totalStages): Monster
    {
        if ($stage >= $totalStages) {
            return $dungeon->bossMonster;
        }

        $trash = Monster::where('enabled', true)
            ->where('id', '!=', $dungeon->boss_monster_id)
            ->whereBetween('min_level', [max(1, $dungeon->min_level - 15), $dungeon->min_level + 5])
            ->inRandomOrder()
            ->first();

        return $trash ?? $dungeon->bossMonster;
    }
}
