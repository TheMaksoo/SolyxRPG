<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\Character;
use App\Models\CharacterQuest;
use App\Models\GameConfig;
use App\Models\Inventory;
use App\Models\Monster;
use App\Models\Quest;
use App\Models\Skill;

class CombatService
{
    public function start(Character $character, Monster $monster): Battle
    {
        $stats = $character->effectiveStats();

        return Battle::create([
            'character_id' => $character->id,
            'monster_id' => $monster->id,
            'character_hp' => $stats['eff_hp_max'],
            'monster_hp' => $monster->hp,
            'status' => 'active',
            'log_json' => [],
        ]);
    }

    /**
     * Resolve one turn. $type is attack|skill|item. Returns the battle result payload.
     */
    public function act(Battle $battle, Character $character, string $type, ?int $skillId = null, ?int $itemId = null): array
    {
        abort_if($battle->status !== 'active', 422, 'Battle already finished.');

        $stats = $character->effectiveStats();
        $log = $battle->log_json ?? [];
        $monster = $battle->monster;

        $playerDmg = 0;
        $healed = 0;

        if ($type === 'attack') {
            $playerDmg = (int) round($stats['eff_atk'] * $this->rand(0.8, 1.2));
            if ($this->rollPercent($stats['crit_chance'])) {
                $playerDmg = (int) round($playerDmg * 1.8);
                $log[] = 'Critical hit!';
            }
        } elseif ($type === 'skill') {
            $skill = Skill::findOrFail($skillId);
            abort_unless($character->skills()->where('skill_id', $skill->id)->exists(), 422, 'Skill not unlocked.');
            abort_if($character->mana < $skill->mp_cost, 422, 'Not enough mana.');

            $character->decrement('mana', $skill->mp_cost);
            $playerDmg = (int) round($stats['eff_atk'] * 1.9 * $this->rand(0.85, 1.15));
            $log[] = "Used {$skill->name}.";
        } elseif ($type === 'item') {
            $inventory = Inventory::where('character_id', $character->id)->where('item_id', $itemId)->firstOrFail();
            abort_if($inventory->qty < 1, 422, 'Out of that item.');

            $item = $inventory->item;
            $healPct = $item->stat_json['heal_hp_pct'] ?? 0;
            $healed = (int) round($stats['eff_hp_max'] * $healPct / 100);
            $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $healed);

            $inventory->decrement('qty');
            if ($inventory->qty <= 0) {
                $inventory->delete();
            }
            $log[] = "Used {$item->name}, healed {$healed} HP.";
        } else {
            abort(422, 'Unknown action.');
        }

        if ($playerDmg > 0) {
            $battle->monster_hp = max(0, $battle->monster_hp - $playerDmg);
            $log[] = "You hit {$monster->name} for {$playerDmg}.";
        }

        if ($battle->monster_hp <= 0) {
            return $this->resolveWin($battle, $character, $log);
        }

        $enemyDmg = max(8, (int) round($monster->atk * $this->rand(0.7, 1.3) - $stats['eff_def'] * 0.25));
        $battle->character_hp = max(0, $battle->character_hp - $enemyDmg);
        $log[] = "{$monster->name} hits you for {$enemyDmg}.";

        if ($battle->character_hp <= 0) {
            return $this->resolveLoss($battle, $character, $log, $stats);
        }

        $battle->log_json = $log;
        $battle->save();

        return ['battle' => $battle->fresh(), 'result' => null];
    }

    private function resolveWin(Battle $battle, Character $character, array $log): array
    {
        $monster = $battle->monster;
        $goldMult = GameConfig::number('gold_mult', 1);
        $xpMult = GameConfig::number('xp_mult', 1);
        $gemMult = GameConfig::number('gem_mult', 1);

        $goldGain = (int) round($monster->gold * $goldMult);
        $xpGain = (int) round($monster->xp * $xpMult);
        $gemGain = (int) round($monster->gems * $gemMult);

        $character->increment('gold', $goldGain);
        $character->increment('gems', $gemGain);

        $leveledUp = $this->grantXp($character, $xpGain);

        $log[] = "Defeated {$monster->name}! +{$goldGain}g +{$xpGain}xp".($gemGain ? " +{$gemGain} gems" : '');
        $battle->update(['status' => 'won', 'log_json' => $log]);

        $this->progressQuests($character, 'battles_won', $monster);

        return [
            'battle' => $battle->fresh(),
            'result' => [
                'outcome' => 'won',
                'gold' => $goldGain,
                'xp' => $xpGain,
                'gems' => $gemGain,
                'leveled_up' => $leveledUp,
                'character' => $character->fresh('attributes_'),
            ],
        ];
    }

    private function resolveLoss(Battle $battle, Character $character, array $log, array $stats): array
    {
        $reviveHp = (int) round($stats['eff_hp_max'] * 0.5);
        $character->update(['hp' => $reviveHp]);
        $battle->character_hp = $reviveHp;
        $log[] = 'You were defeated and revived at 50% HP.';
        $battle->update(['status' => 'lost', 'log_json' => $log]);

        return [
            'battle' => $battle->fresh(),
            'result' => ['outcome' => 'lost', 'character' => $character->fresh('attributes_')],
        ];
    }

    /** Applies xp, handling multi-level-ups; returns number of levels gained. */
    private function grantXp(Character $character, int $xpGain): int
    {
        $xp = $character->xp + $xpGain;
        $level = $character->level;
        $levelsGained = 0;
        $attrPoints = $character->attribute_points;
        $skillPoints = $character->skill_points;

        $xpMax = Character::xpForLevel($level);
        while ($xp >= $xpMax) {
            $xp -= $xpMax;
            $level++;
            $attrPoints += 3;
            $skillPoints += 1;
            $levelsGained++;
            $xpMax = Character::xpForLevel($level);
        }

        $character->update([
            'xp' => $xp,
            'level' => $level,
            'attribute_points' => $attrPoints,
            'skill_points' => $skillPoints,
        ]);

        return $levelsGained;
    }

    private function progressQuests(Character $character, string $kind, Monster $monster): void
    {
        $quests = Quest::where('enabled', true)->get()->filter(
            fn (Quest $q) => ($q->goal_json['kind'] ?? null) === $kind
                || (($q->goal_json['kind'] ?? null) === 'boss_kill' && ($q->goal_json['monster_key'] ?? null) === $monster->key)
        );

        foreach ($quests as $quest) {
            $progress = CharacterQuest::firstOrCreate(
                ['character_id' => $character->id, 'quest_id' => $quest->id],
                ['progress' => 0]
            );
            if ($progress->completed) {
                continue;
            }

            $target = $quest->goal_json['target'] ?? 1;
            $progress->increment('progress');
            if ($progress->fresh()->progress >= $target) {
                $progress->update(['completed' => true]);
            }
        }
    }

    private function rand(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    private function rollPercent(float $percentChance): bool
    {
        return (mt_rand() / mt_getrandmax() * 100) < $percentChance;
    }
}
