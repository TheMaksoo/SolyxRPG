<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\Character;
use App\Models\CharacterPet;
use App\Models\CharacterQuest;
use App\Models\GameConfig;
use App\Models\Inventory;
use App\Models\Monster;
use App\Models\Quest;
use App\Models\Skill;

class CombatService
{
    public function __construct(
        private AchievementService $achievements = new AchievementService(),
        private BattlePassService $battlePass = new BattlePassService(),
        private GradeService $grades = new GradeService(),
        private SkillService $skills = new SkillService(),
    ) {}

    public function start(Character $character, Monster $monster, string $grade = 'common'): Battle
    {
        $stats = $character->effectiveStats();
        $startingHp = (int) min($stats['eff_hp_max'], max(1, $character->hp));
        $monsterHp = (int) round($monster->hp * $this->grades->hpMult($grade));

        return Battle::create([
            'character_id' => $character->id,
            'monster_id' => $monster->id,
            'grade' => $grade,
            'character_hp' => $startingHp,
            'monster_hp' => $monsterHp,
            'monster_hp_max' => $monsterHp,
            'status' => 'active',
            'log_json' => [],
        ]);
    }

    /** Trickles HP (into the battle's own HP counter) and mana (on the character) for time spent between turns. */
    public function regenInBattle(Battle $battle, Character $character): void
    {
        if ($battle->status !== 'active') {
            return;
        }

        $elapsed = max(0, now()->getTimestamp() - $battle->updated_at->getTimestamp());
        $ticks = intdiv($elapsed, 5);
        if ($ticks <= 0) {
            return;
        }

        $stats = $character->effectiveStats();

        $newHp = min($stats['eff_hp_max'], $battle->character_hp + $ticks * $character->regenPerTick());
        if ($newHp !== $battle->character_hp) {
            $battle->character_hp = $newHp;
            $battle->save();
        }

        $newMana = min($stats['eff_mp_max'], $character->mana + $ticks * $character->manaRegenPerTick());
        if ($newMana !== $character->mana) {
            $character->mana = $newMana;
            $character->save();
        }
    }

    /**
     * Resolve one turn. $type is attack|skill|item. Returns the battle result payload.
     */
    public function act(Battle $battle, Character $character, string $type, ?int $skillId = null, ?int $itemId = null): array
    {
        abort_if($battle->status !== 'active', 422, 'Battle already finished.');

        $this->regenInBattle($battle, $character);

        $stats = $character->effectiveStats();
        $log = $battle->log_json ?? [];
        $monster = $battle->monster;

        $playerDmg = 0;
        $healed = 0;

        if ($type === 'attack') {
            $playerDmg = (int) round($stats['eff_atk'] * $this->rand(0.8, 1.2));
            if ($this->rollPercent($stats['crit_chance'])) {
                $playerDmg = (int) round($playerDmg * $stats['crit_damage_mult']);
                $log[] = 'Critical hit!';
            }
        } elseif ($type === 'skill') {
            $skill = Skill::findOrFail($skillId);
            $characterSkill = $character->skills()->where('skill_id', $skill->id)->first();
            abort_unless($characterSkill, 422, 'Skill not unlocked.');
            abort_if($character->mana < $skill->mp_cost, 422, 'Not enough mana.');

            $character->decrement('mana', $skill->mp_cost);
            $mult = $this->skills->damageMultiplier($skill, $characterSkill->level);
            $playerDmg = (int) round($stats['eff_atk'] * $mult * $this->rand(0.85, 1.15));
            $log[] = "Used {$skill->name} (rank {$characterSkill->level}).";
        } elseif ($type === 'item') {
            $inventory = Inventory::where('character_id', $character->id)->where('item_id', $itemId)->firstOrFail();
            abort_if($inventory->qty < 1, 422, 'Out of that item.');

            $item = $inventory->item;
            $healHpPct = $item->stat_json['heal_hp_pct'] ?? 0;
            $healMpPct = $item->stat_json['heal_mp_pct'] ?? 0;
            $healed = (int) round($stats['eff_hp_max'] * $healHpPct / 100);
            $healedMp = (int) round($stats['eff_mp_max'] * $healMpPct / 100);
            $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $healed);
            if ($healedMp > 0) {
                $character->mana = min($stats['eff_mp_max'], $character->mana + $healedMp);
                $character->save();
            }

            $inventory->decrement('qty');
            if ($inventory->qty <= 0) {
                $inventory->delete();
            }
            $healText = array_filter([$healed > 0 ? "{$healed} HP" : null, $healedMp > 0 ? "{$healedMp} MP" : null]);
            $log[] = "Used {$item->name}, restored ".implode(' and ', $healText).'.';
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

        if ($this->rollPercent($stats['dodge_chance'] ?? 0)) {
            $log[] = "You dodge {$monster->name}'s attack!";
            $battle->log_json = $log;
            $battle->save();

            $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

            return [
                'battle' => $battle->fresh('monster'),
                'result' => null,
                'character' => $freshCharacter,
                'stats' => $freshCharacter->effectiveStats(),
            ];
        }

        $enemyDmg = max(8, (int) round($monster->atk * $this->grades->atkMult($battle->grade) * $this->rand(0.7, 1.3) - $stats['eff_def'] * 0.25));
        $battle->character_hp = max(0, $battle->character_hp - $enemyDmg);
        $log[] = "{$monster->name} hits you for {$enemyDmg}.";

        if ($battle->character_hp <= 0) {
            if (! empty($stats['has_undying']) && ! $battle->revived_with_skill) {
                $battle->character_hp = 1;
                $battle->revived_with_skill = true;
                $log[] = 'Undying triggers! You survive with 1 HP.';
            } else {
                return $this->resolveLoss($battle, $character, $log, $stats);
            }
        }

        $battle->log_json = $log;
        $battle->save();

        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

        return [
            'battle' => $battle->fresh('monster'),
            'result' => null,
            'character' => $freshCharacter,
            'stats' => $freshCharacter->effectiveStats(),
        ];
    }

    public function flee(Battle $battle, Character $character): array
    {
        abort_if($battle->status !== 'active', 422, 'Battle already finished.');

        $stats = $character->effectiveStats();
        $fleeDmg = (int) round($stats['eff_hp_max'] * 0.1);
        $newHp = max(1, $battle->character_hp - $fleeDmg);

        $character->hp = $newHp;
        $character->save();

        $log = $battle->log_json ?? [];
        $log[] = "You fled the battle, taking {$fleeDmg} damage on the way out.";
        $battle->update(['status' => 'fled', 'log_json' => $log, 'character_hp' => $newHp]);

        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

        return [
            'battle' => $battle->fresh('monster'),
            'result' => ['outcome' => 'fled', 'hp_lost' => $fleeDmg],
            'character' => $freshCharacter,
            'stats' => $freshCharacter->effectiveStats(),
        ];
    }

    private function resolveWin(Battle $battle, Character $character, array $log): array
    {
        $monster = $battle->monster;
        $stats = $character->effectiveStats();
        $luck = max(0, (int) ($stats['luck'] ?? 0));
        $luckPerPoint = GameConfig::number('luck_combat_bonus_per_point', 0.01);
        $luckCap = GameConfig::number('luck_combat_bonus_cap', 0.75);
        $xpFactor = GameConfig::number('luck_xp_bonus_factor', 0.4);
        $gemFactor = GameConfig::number('luck_gem_bonus_factor', 0.5);
        $luckBonus = min($luckCap, $luck * $luckPerPoint);

        $goldMult = GameConfig::number('gold_mult', 1);
        $xpMult = GameConfig::number('xp_mult', 1);
        $gemMult = GameConfig::number('gem_mult', 1);
        $vipGoldXpBonus = ($character->user?->vipGoldXpBonusPct() ?? 0) / 100;

        $petXpBonus = max(0, (float) ($stats['pet_xp_bonus_pct'] ?? 0)) / 100;
        $gradeRewardMult = $this->grades->rewardMult($battle->grade);

        $goldGain = (int) round($monster->gold * $goldMult * $gradeRewardMult * (1 + $luckBonus + $vipGoldXpBonus));
        $xpGain = (int) round($monster->xp * $xpMult * $gradeRewardMult * (1 + ($luckBonus * $xpFactor) + $petXpBonus + $vipGoldXpBonus));
        $gemGain = (int) round($monster->gems * $gemMult * $gradeRewardMult * (1 + ($luckBonus * $gemFactor)));

        $character->increment('gold', $goldGain);
        $character->increment('gems', $gemGain);
        $character->increment('battles_won');
        $character->hp = max(1, (int) $battle->character_hp);
        $character->save();
        if ($monster->is_boss) {
            $character->increment('bosses_slain');
        }

        $leveledUp = $this->grantXp($character, $xpGain);
        $petResult = $this->grantPetXp($character, (int) round($xpGain * 0.2));
        $this->battlePass->addXp($character, (int) round($xpGain * 0.3));

        $gradeLabel = $battle->grade !== 'common' ? $this->grades->meta($battle->grade)['label'].' ' : '';
        $log[] = "Defeated {$gradeLabel}{$monster->name}! +{$goldGain}g +{$xpGain}xp".($gemGain ? " +{$gemGain} gems" : '').($luck > 0 ? " (Luck +".(int) round($luckBonus * 100)."%)" : '');
        if ($petResult) {
            $log[] = "{$petResult['name']} gained companion XP.".($petResult['leveled_up'] ? " Now level {$petResult['level']}!" : '');
        }
        $battle->update(['status' => 'won', 'log_json' => $log]);

        $this->progressQuests($character, 'battles_won', $monster);
        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);
        $newAchievements = $this->achievements->check($freshCharacter, $monster->is_boss ? $monster : null);

        return [
            'battle' => $battle->fresh('monster'),
            'result' => [
                'outcome' => 'won',
                'gold' => $goldGain,
                'xp' => $xpGain,
                'gems' => $gemGain,
                'leveled_up' => $leveledUp,
                'pet' => $petResult,
                'character' => $freshCharacter,
                'stats' => $freshCharacter->effectiveStats(),
                'achievements' => $newAchievements,
            ],
        ];
    }

    /** Grants XP to the character's active pet, if any, handling level-ups up to the pet level cap. */
    private function grantPetXp(Character $character, int $xpGain): ?array
    {
        $pet = $character->activePet();
        if (! $pet || $xpGain <= 0 || $pet->level >= CharacterPet::MAX_LEVEL) {
            return null;
        }

        $xp = $pet->xp + $xpGain;
        $level = $pet->level;
        $leveledUp = false;

        $xpMax = CharacterPet::xpForLevel($level);
        while ($level < CharacterPet::MAX_LEVEL && $xp >= $xpMax) {
            $xp -= $xpMax;
            $level++;
            $leveledUp = true;
            $xpMax = CharacterPet::xpForLevel($level);
        }
        if ($level >= CharacterPet::MAX_LEVEL) {
            $xp = 0;
        }

        $pet->update(['xp' => $xp, 'level' => $level]);

        return ['name' => $pet->pet->name, 'level' => $level, 'leveled_up' => $leveledUp];
    }

    private function resolveLoss(Battle $battle, Character $character, array $log, array $stats): array
    {
        $reviveHp = (int) round($stats['eff_hp_max'] * 0.4);
        $penalty = $this->applyDeathPenalty($character);

        $character->hp = $reviveHp;
        $character->increment('battles_lost');
        $battle->character_hp = $reviveHp;

        $log[] = 'You were defeated and revived at 40% HP.';
        if ($penalty['gold_lost'] > 0 || $penalty['xp_lost'] > 0) {
            $log[] = "Lost {$penalty['gold_lost']}g and {$penalty['xp_lost']} xp.";
        }
        if ($penalty['levels_lost'] > 0) {
            $log[] = "Dropped to level {$character->level}! Lost ".($penalty['levels_lost'] * 3)." attribute pts and {$penalty['levels_lost']} skill pts.";
        }
        $battle->update(['status' => 'lost', 'log_json' => $log]);

        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

        return [
            'battle' => $battle->fresh('monster'),
            'result' => [
                'outcome' => 'lost',
                'gold_lost' => $penalty['gold_lost'],
                'xp_lost' => $penalty['xp_lost'],
                'levels_lost' => $penalty['levels_lost'],
                'character' => $freshCharacter,
                'stats' => $freshCharacter->effectiveStats(),
            ],
        ];
    }

    /**
     * Death penalty: lose a slice of gold and xp. Running xp to 0 and below strips a level
     * (and that level's attribute/skill points, floored at 0 — already-spent points aren't clawed back).
     */
    private function applyDeathPenalty(Character $character): array
    {
        $goldLossPct = GameConfig::number('death_gold_loss_pct', 8);
        $xpLossPct = GameConfig::number('death_xp_loss_pct', 15);

        $goldLost = (int) min($character->gold, round($character->gold * $goldLossPct / 100));
        $xpLost = (int) round(Character::xpForLevel($character->level) * $xpLossPct / 100);

        $level = $character->level;
        $xp = $character->xp - $xpLost;
        $levelsLost = 0;

        while ($xp < 0 && $level > 1) {
            $level--;
            $xp += Character::xpForLevel($level);
            $levelsLost++;
        }
        $xp = max(0, $xp);

        $character->gold = max(0, $character->gold - $goldLost);
        $character->xp = $xp;
        $character->level = $level;
        if ($levelsLost > 0) {
            $character->attribute_points = max(0, $character->attribute_points - $levelsLost * 3);
            $character->skill_points = max(0, $character->skill_points - $levelsLost);
        }
        $character->save();

        return ['gold_lost' => $goldLost, 'xp_lost' => $xpLost, 'levels_lost' => $levelsLost];
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
