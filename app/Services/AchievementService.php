<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\Character;
use App\Models\CharacterAchievement;
use App\Models\Monster;

class AchievementService
{
    /** Checks all achievements against current character state and grants any newly met ones. */
    public function check(Character $character, ?Monster $defeatedBoss = null): array
    {
        $earnedIds = $character->achievements()->pluck('achievement_id');
        $granted = [];

        foreach (Achievement::where('enabled', true)->whereNotIn('id', $earnedIds)->get() as $achievement) {
            if ($this->meets($character, $achievement, $defeatedBoss)) {
                CharacterAchievement::create([
                    'character_id' => $character->id,
                    'achievement_id' => $achievement->id,
                    'earned_at' => now(),
                ]);
                $granted[] = $achievement;
            }
        }

        return $granted;
    }

    private function meets(Character $character, Achievement $achievement, ?Monster $defeatedBoss): bool
    {
        $req = $achievement->requirement_json;

        return match ($req['kind'] ?? null) {
            'battles_won' => $character->battles_won >= $req['target'],
            'bosses_slain' => $character->bosses_slain >= $req['target'],
            'level' => $character->level >= $req['target'],
            'gold' => $character->gold >= $req['target'],
            'boss_kill' => $defeatedBoss && $defeatedBoss->key === $req['monster_key'],
            default => false,
        };
    }
}
