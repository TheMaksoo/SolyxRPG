<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\BattleMonster;
use App\Models\Character;
use App\Models\CharacterPet;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Monster;
use App\Models\Skill;

class CombatService
{
    /** Flavor verb for the plain-attack log line, keyed by the equipped weapon's weapon_category. */
    private const ATTACK_VERBS = [
        'sword' => 'slash',
        'dagger' => 'stab',
        'bow' => 'fire an arrow into',
        'staff' => 'blast',
        'axe' => 'chop down on',
        'blunt' => 'crush',
    ];

    public function __construct(
        private AchievementService $achievements = new AchievementService(),
        private BattlePassService $battlePass = new BattlePassService(),
        private GradeService $grades = new GradeService(),
        private SkillService $skills = new SkillService(),
        private DurabilityService $durability = new DurabilityService(),
        private MonsterAiService $monsterAi = new MonsterAiService(),
        private QuestService $quests = new QuestService(),
    ) {}

    /** $extraMonsters spawns additional "adds" fighting alongside $monster — a multi-enemy boss encounter
     * instead of the usual 1v1 (see DungeonService). Adds only ever basic-attack; $monster keeps the full
     * ability/cooldown AI regardless of how many adds are alongside it. */
    public function start(Character $character, Monster $monster, string $grade = 'common', array $extraMonsters = []): Battle
    {
        $stats = $character->effectiveStats();
        $startingHp = (int) min($stats['eff_hp_max'], max(1, $character->hp));
        $monsterHp = (int) round($monster->hp * $this->grades->hpMult($grade));

        $battle = Battle::create([
            'character_id' => $character->id,
            'monster_id' => $monster->id,
            'grade' => $grade,
            'character_hp' => $startingHp,
            'monster_hp' => $monsterHp,
            'monster_hp_max' => $monsterHp,
            'status' => 'active',
            'log_json' => [],
        ]);

        foreach (array_values($extraMonsters) as $i => $extra) {
            $hp = (int) round($extra->hp * $this->grades->hpMult($grade));
            BattleMonster::create([
                'battle_id' => $battle->id,
                'monster_id' => $extra->id,
                'hp' => $hp,
                'hp_max' => $hp,
                'slot' => $i + 1,
            ]);
        }

        return $battle;
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
     * Resolve one turn. $type is attack|skill|item. $targetMonsterId picks which "add" (a BattleMonster id)
     * a single-target action hits — null (the default) targets the primary monster. AOE skills ignore it
     * and hit the primary plus every living add. Returns the battle result payload.
     */
    public function act(Battle $battle, Character $character, string $type, ?int $skillId = null, ?int $itemId = null, ?int $targetMonsterId = null): array
    {
        abort_if($battle->status !== 'active', 422, 'Battle already finished.');

        $this->regenInBattle($battle, $character);

        $stats = $character->effectiveStats();
        $log = $battle->log_json ?? [];
        $monster = $battle->monster;
        $weaponRow = $this->equippedGear($character, 'weapon');
        $armorRow = $this->equippedGear($character, 'armor');

        // Turn-based skill cooldowns: "rounds remaining" per skill id, scoped to this battle (see
        // Battle::skill_cooldowns_json) rather than real elapsed time — one act() call is one round, so
        // every ability on the map ticks down by 1 every round regardless of what action was taken.
        // Assigning it back onto $battle now means it rides along with whichever save()/update() call
        // below ends up persisting this turn, on every return path.
        $skillCooldowns = $battle->skill_cooldowns_json ?? [];
        foreach ($skillCooldowns as $sid => $remaining) {
            $skillCooldowns[$sid] = max(0, $remaining - 1);
        }
        $battle->skill_cooldowns_json = $skillCooldowns;

        $playerDmg = 0;
        $healed = 0;
        $isAoe = false;

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
            $roundsLeft = $skillCooldowns[$skill->id] ?? 0;
            abort_if($roundsLeft > 0, 422, "{$skill->name} is on cooldown for {$roundsLeft} more round".($roundsLeft > 1 ? 's' : '').'.');
            abort_if($character->mana < $skill->mp_cost, 422, 'Not enough mana.');

            $character->decrement('mana', $skill->mp_cost);
            if ($skill->cooldown_rounds > 0) {
                // Ranger identity: high-DPS, low-cooldown skirmisher (see Character::classPassiveBonuses()
                // for the matching flat-ATK/dodge half of this). Applies class-wide rather than only to
                // ranger-branch skills, but since a ranger can only ever unlock ranger-scoped skills
                // (class_scope in SkillSeeder), it only ever actually fires for ranger-usable skills.
                $roundsSet = $character->base_class === 'ranger' ? max(1, $skill->cooldown_rounds - 1) : $skill->cooldown_rounds;
                $skillCooldowns[$skill->id] = $roundsSet;
                $battle->skill_cooldowns_json = $skillCooldowns;
            }

            if ($this->skills->isHeal($skill)) {
                $healPct = $this->skills->healPct($skill, $characterSkill->level);
                $healed = (int) round($stats['eff_hp_max'] * $healPct / 100);
                $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $healed);
                $log[] = "Used {$skill->name} (rank {$characterSkill->level}), restored {$healed} HP.";
            } else {
                $mult = $this->skills->damageMultiplier($skill, $characterSkill->level);
                $playerDmg = (int) round($stats['eff_atk'] * $mult * $this->rand(0.85, 1.15));
                $isAoe = (bool) ($skill->effect_json['aoe'] ?? false);
                $log[] = "Used {$skill->name} (rank {$characterSkill->level}).";
            }
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
            $log = $this->applyPlayerDamage($battle, $playerDmg, $isAoe, $type, $weaponRow, $targetMonsterId, $log);
            $this->decayGear($weaponRow);
        }

        if ($this->allEnemiesDefeated($battle)) {
            return $this->resolveWin($battle, $character, $log);
        }

        $hasAdds = $battle->battleMonsters->isNotEmpty();
        if ($this->rollPercent($stats['dodge_chance'] ?? 0)) {
            $log[] = $hasAdds ? 'You dodge the incoming attacks!' : "You dodge {$monster->name}'s attack!";
            $battle->log_json = $log;
            $battle->save();

            $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

            return [
                'battle' => $battle->fresh(['monster', 'battleMonsters.monster']),
                'result' => null,
                'character' => $freshCharacter,
                'stats' => $freshCharacter->effectiveStats(),
            ];
        }

        $log = $this->resolveEnemyTurn($battle, $stats, $armorRow, $log);

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
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster']),
            'result' => null,
            'character' => $freshCharacter,
            'stats' => $freshCharacter->effectiveStats(),
        ];
    }

    /** Applies the player's already-rolled damage to the right target(s) and appends the matching log line(s).
     * AOE hits the primary plus every living add for the same amount; single-target hits $targetMonsterId's
     * add if it's alive, else falls back to the primary — a stale/invalid target never errors, it just
     * redirects to the boss. Returns the updated log array. */
    private function applyPlayerDamage(Battle $battle, int $dmg, bool $isAoe, string $type, ?Inventory $weaponRow, ?int $targetMonsterId, array $log): array
    {
        $monster = $battle->monster;

        if ($isAoe) {
            $hitNames = [$monster->name];
            $battle->monster_hp = max(0, $battle->monster_hp - $dmg);
            foreach ($battle->battleMonsters as $extra) {
                if ($extra->hp <= 0) {
                    continue;
                }
                $extra->hp = max(0, $extra->hp - $dmg);
                $extra->save();
                $hitNames[] = $extra->monster->name;
            }
            $log[] = 'You hit '.implode(', ', $hitNames)." for {$dmg} each.";

            return $log;
        }

        $target = $targetMonsterId ? $battle->battleMonsters->firstWhere('id', $targetMonsterId) : null;
        if ($target && $target->hp > 0) {
            $target->hp = max(0, $target->hp - $dmg);
            $target->save();
            $hitName = $target->monster->name;
        } else {
            $battle->monster_hp = max(0, $battle->monster_hp - $dmg);
            $hitName = $monster->name;
        }

        if ($type === 'attack') {
            $verb = self::ATTACK_VERBS[$weaponRow?->item->weapon_category] ?? 'hit';
            $log[] = "You {$verb} {$hitName} for {$dmg}.";
        } else {
            $log[] = "You hit {$hitName} for {$dmg}.";
        }

        return $log;
    }

    /** Runs the primary monster's full ability/cooldown AI turn (if it's still alive), then has every living
     * "add" basic-attack once each. Returns the updated log array. */
    private function resolveEnemyTurn(Battle $battle, array $stats, ?Inventory $armorRow, array $log): array
    {
        $monster = $battle->monster;

        if ($battle->monster_hp > 0) {
            [$ability, $cooldowns] = $this->monsterAi->choose($monster, $battle->monster_cooldowns_json ?? []);
            $battle->monster_cooldowns_json = $cooldowns;

            if ($ability['type'] === 'regen') {
                $maxHp = $battle->monster_hp_max ?? $monster->hp;
                $healed = min($maxHp - $battle->monster_hp, (int) round($maxHp * ($ability['heal_pct'] ?? 0) / 100));
                $battle->monster_hp += $healed;
                $log[] = "{$monster->name} uses {$ability['name']} and regenerates {$healed} HP!";
            } else {
                $hits = max(1, $ability['hits'] ?? 1);
                $gradeAtkMult = $this->grades->atkMult($battle->grade);
                $enemyDmg = 0;
                for ($i = 0; $i < $hits; $i++) {
                    $enemyDmg += max(2, (int) round($monster->atk * $gradeAtkMult * ($ability['dmg_mult'] ?? 1.0) * $this->rand(0.7, 1.3) - $stats['eff_def'] * 0.25));
                }
                $battle->character_hp = max(0, $battle->character_hp - $enemyDmg);
                if (($ability['key'] ?? null) === 'basic_attack') {
                    $log[] = "{$monster->name} hits you for {$enemyDmg}.";
                } else {
                    $hitNote = $hits > 1 ? " ({$hits} hits)" : '';
                    $log[] = "{$monster->name} uses {$ability['name']} for {$enemyDmg}{$hitNote}.";
                }
                $this->decayGear($armorRow);
            }
        }

        // Adds only ever basic-attack — no ability/cooldown AI of their own, keeping the primary monster
        // as the one mechanically-interesting fighter in a multi-enemy encounter.
        foreach ($battle->battleMonsters as $extra) {
            if ($extra->hp <= 0) {
                continue;
            }
            $gradeAtkMult = $this->grades->atkMult($battle->grade);
            $addDmg = max(2, (int) round($extra->monster->atk * $gradeAtkMult * $this->rand(0.7, 1.3) - $stats['eff_def'] * 0.25));
            $battle->character_hp = max(0, $battle->character_hp - $addDmg);
            $log[] = "{$extra->monster->name} hits you for {$addDmg}.";
        }

        return $log;
    }

    /** True once the primary monster and every "add" (if any) are at 0 HP. */
    private function allEnemiesDefeated(Battle $battle): bool
    {
        if ($battle->monster_hp > 0) {
            return false;
        }

        foreach ($battle->battleMonsters as $extra) {
            if ($extra->hp > 0) {
                return false;
            }
        }

        return true;
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
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster']),
            'result' => ['outcome' => 'fled', 'hp_lost' => $fleeDmg],
            'character' => $freshCharacter,
            'stats' => $freshCharacter->effectiveStats(),
        ];
    }

    private function resolveWin(Battle $battle, Character $character, array $log): array
    {
        $monster = $battle->monster;
        $extraMonsters = $battle->battleMonsters->map(fn (BattleMonster $bm) => $bm->monster);
        $allMonsters = collect([$monster])->concat($extraMonsters);

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
        $guildXpBonus = ($character->guildMembership?->guild?->xp_perk ?? 0) / 100;
        $guildGoldFindBonus = ($character->guildMembership?->guild?->upgradeBonusPct('gold_find') ?? 0) / 100;
        $guildXpUpgradeBonus = ($character->guildMembership?->guild?->upgradeBonusPct('xp') ?? 0) / 100;

        $petXpBonus = max(0, (float) ($stats['pet_xp_bonus_pct'] ?? 0)) / 100;
        $gradeRewardMult = $this->grades->rewardMult($battle->grade);

        $goldGain = (int) round($allMonsters->sum('gold') * $goldMult * $gradeRewardMult * (1 + $luckBonus + $vipGoldXpBonus + $guildXpBonus + $guildGoldFindBonus));
        $xpGain = (int) round($allMonsters->sum('xp') * $xpMult * $gradeRewardMult * (1 + ($luckBonus * $xpFactor) + $petXpBonus + $vipGoldXpBonus + $guildXpBonus + $guildXpUpgradeBonus));
        $gemGain = (int) round($allMonsters->sum('gems') * $gemMult * $gradeRewardMult * (1 + ($luckBonus * $gemFactor)));

        $character->increment('gold', $goldGain);
        $character->increment('gems', $gemGain);
        if ($gemGain > 0) {
            GemLedger::log($character, $gemGain, "battle_win:{$monster->key}");
        }
        $character->increment('battles_won');
        $character->hp = max(1, (int) $battle->character_hp);
        $character->save();
        if ($character->guildMembership) {
            $character->guildMembership->guild->addXp(2);
        }
        $isBoss = $allMonsters->contains(fn (Monster $m) => $m->is_boss);
        if ($isBoss) {
            $character->increment('bosses_slain');
        }

        $leveledUp = $this->grantXp($character, $xpGain);
        $petResults = $this->grantPetXp($character, (int) round($xpGain * 0.2));
        $this->battlePass->addXp($character, (int) round($xpGain * 0.3));
        $this->grantPartyShare($character, $goldGain, $xpGain, $gemGain);

        $gradeLabel = $battle->grade !== 'common' ? $this->grades->meta($battle->grade)['label'].' ' : '';
        $defeatedNames = $allMonsters->pluck('name')->implode(', ');
        $log[] = "Defeated {$gradeLabel}{$defeatedNames}! +{$goldGain}g +{$xpGain}xp".($gemGain ? " +{$gemGain} gems" : '').($luck > 0 ? " (Luck +".(int) round($luckBonus * 100)."%)" : '');
        foreach ($petResults as $petResult) {
            $log[] = "{$petResult['name']} gained companion XP.".($petResult['leveled_up'] ? " Now level {$petResult['level']}!" : '');
        }
        $battle->update(['status' => 'won', 'log_json' => $log]);

        $this->quests->progress($character, 'battles_won', $monster);
        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);
        $newAchievements = $this->achievements->check($freshCharacter, $monster->is_boss ? $monster : null);

        return [
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster']),
            'result' => [
                'outcome' => 'won',
                'gold' => $goldGain,
                'xp' => $xpGain,
                'gems' => $gemGain,
                'leveled_up' => $leveledUp,
                'pets' => $petResults,
                'character' => $freshCharacter,
                'stats' => $freshCharacter->effectiveStats(),
                'achievements' => $newAchievements,
            ],
        ];
    }

    /** Grants XP to every one of the character's active pets, handling level-ups up to the pet level cap. */
    private function grantPetXp(Character $character, int $xpGain): array
    {
        $results = [];

        foreach ($character->activePets() as $pet) {
            if ($xpGain <= 0 || $pet->level >= CharacterPet::MAX_LEVEL) {
                continue;
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

            $results[] = ['name' => $pet->pet->name, 'level' => $level, 'leveled_up' => $leveledUp];
        }

        return $results;
    }

    /** Party members currently in the same zone get a smaller cut of these rewards too — the winner
     * always keeps their own full amount, this is purely an added incentive to actually adventure
     * together rather than solo-grind side by side for no benefit. "Same zone" is a light proxy for
     * "actively playing together" (an idle character's zone never changes), on top of which we also
     * require the partner to have touched the server recently, so a parked alt account can't pull a
     * passive income by sitting in the same zone forever. */
    private function grantPartyShare(Character $character, int $goldGain, int $xpGain, int $gemGain): void
    {
        $party = $character->partyMembership?->party;
        if (! $party) {
            return;
        }

        $sharePct = 0.2;
        $recentlyActiveSince = now()->subMinutes(10);

        $partners = $party->members()
            ->where('character_id', '!=', $character->id)
            ->with('character')
            ->get()
            ->pluck('character')
            ->filter(fn (?Character $c) => $c
                && $c->current_zone_id === $character->current_zone_id
                && $c->updated_at?->greaterThan($recentlyActiveSince));

        foreach ($partners as $partner) {
            $goldShare = (int) round($goldGain * $sharePct);
            $xpShare = (int) round($xpGain * $sharePct);
            $gemShare = (int) round($gemGain * $sharePct);

            if ($goldShare > 0) {
                $partner->increment('gold', $goldShare);
            }
            if ($gemShare > 0) {
                $partner->increment('gems', $gemShare);
                GemLedger::log($partner, $gemShare, "party_share:{$character->name}");
            }
            if ($xpShare > 0) {
                $this->grantXp($partner, $xpShare);
            }
        }
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
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster']),
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

    /** Applies xp, handling multi-level-ups (capped at Character::MAX_LEVEL); returns number of levels gained. */
    private function grantXp(Character $character, int $xpGain): int
    {
        $xp = $character->xp + $xpGain;
        $level = $character->level;
        $levelsGained = 0;
        $attrPoints = $character->attribute_points;
        $skillPoints = $character->skill_points;

        $xpMax = Character::xpForLevel($level);
        while ($level < Character::MAX_LEVEL && $xp >= $xpMax) {
            $xp -= $xpMax;
            $level++;
            $attrPoints += 3;
            $skillPoints += 1;
            $levelsGained++;
            $xpMax = Character::xpForLevel($level);
        }
        if ($level >= Character::MAX_LEVEL) {
            $xp = 0;
        }

        $character->update([
            'xp' => $xp,
            'level' => $level,
            'attribute_points' => $attrPoints,
            'skill_points' => $skillPoints,
        ]);

        return $levelsGained;
    }

    private function rand(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    private function rollPercent(float $percentChance): bool
    {
        return (mt_rand() / mt_getrandmax() * 100) < $percentChance;
    }

    /** The character's currently-equipped weapon or armor Inventory row (with item loaded), or null if nothing's equipped there. */
    private function equippedGear(Character $character, string $type): ?Inventory
    {
        return Inventory::where('character_id', $character->id)
            ->where('equipped', true)
            ->whereHas('item', fn ($q) => $q->where('type', $type))
            ->with('item')
            ->first();
    }

    /** Wears the given equipped gear row down by one use. No-ops if nothing's equipped there or it predates durability tracking. */
    private function decayGear(?Inventory $row): void
    {
        if ($row && $row->durability_max !== null) {
            $row->update(['durability' => max(0, $row->durability - DurabilityService::DECAY_PER_ACTION)]);
        }
    }
}
