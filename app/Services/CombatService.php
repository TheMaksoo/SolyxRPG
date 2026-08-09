<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\BattleCompanion;
use App\Models\BattleMonster;
use App\Models\Character;
use App\Models\CharacterPet;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Monster;
use App\Models\Skill;
use App\Models\TamedCompanion;
use App\Models\TamedCompanionLog;
use App\Models\Zone;

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

        // Carry over skill cooldowns from the character's most recent battle so cooldowns persist
        // across fights instead of resetting every time. Each cooldown ticks down by 1 (walking into
        // a new fight counts as one round passing).
        $previousBattle = Battle::where('character_id', $character->id)
            ->orderByDesc('id')
            ->first();
        $cooldowns = [];
        if ($previousBattle && $previousBattle->skill_cooldowns_json) {
            foreach ($previousBattle->skill_cooldowns_json as $skillId => $rounds) {
                $remaining = max(0, $rounds - 1);
                if ($remaining > 0) {
                    $cooldowns[$skillId] = $remaining;
                }
            }
        }

        $battle = Battle::create([
            'character_id' => $character->id,
            'monster_id' => $monster->id,
            'grade' => $grade,
            'character_hp' => $startingHp,
            'monster_hp' => $monsterHp,
            'monster_hp_max' => $monsterHp,
            'status' => 'active',
            'log_json' => [],
            'skill_cooldowns_json' => $cooldowns,
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

        // Tamed companions carry their HP across battles (regenTick() catches up passive regen since
        // the last fight) rather than getting a full heal every encounter — a companion still recovering
        // from its last fight joins this one already hurt, and one at 0 hp (dead) never joins at all.
        $companions = TamedCompanion::where('character_id', $character->id)
            ->whereNull('archived_at')
            ->where('active', true)
            ->get();
        foreach ($companions->values() as $i => $companion) {
            if ($companion->regenTick()) {
                $companion->save();
            }
            if ($companion->current_hp <= 0) {
                continue;
            }
            BattleCompanion::create([
                'battle_id' => $battle->id,
                'tamed_companion_id' => $companion->id,
                'hp' => $companion->current_hp,
                'hp_max' => $companion->effectiveHpMax(),
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
        $battle->character_hp = $newHp;
        // touch() unconditionally, not just when HP changed — battle.updated_at is the tick anchor for
        // BOTH hp and mana above. If hp is already at cap while mana isn't, a plain save() would skip
        // the query entirely (nothing dirty) and leave the anchor stale, so the next call recomputes
        // $ticks from the same old timestamp and re-credits mana for time that was already paid out.
        $battle->touch();

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

        // A heal skill is a free action exactly like drinking a potion — it doesn't end your turn, so
        // it's decided before the cooldown tick below (which also treats it like an item, see that
        // comment) rather than only after the skill branch runs. $skill is fetched once here and reused
        // in the skill branch below rather than queried twice.
        $skill = $type === 'skill' ? Skill::findOrFail($skillId) : null;
        $isFreeAction = $type === 'item' || ($skill && $this->skills->isHeal($skill));

        // Turn-based skill cooldowns: "rounds remaining" per skill id, scoped to this battle (see
        // Battle::skill_cooldowns_json) rather than real elapsed time — one act() call is one round, so
        // every ability on the map ticks down by 1 every round regardless of what action was taken.
        // Assigning it back onto $battle now means it rides along with whichever save()/update() call
        // below ends up persisting this turn, on every return path. Using an item (or a heal skill —
        // see $isFreeAction above) is a free action so cooldowns don't tick for it either — drinking a
        // potion or topping off HP shouldn't quietly burn a round off your other abilities.
        $skillCooldowns = $battle->skill_cooldowns_json ?? [];
        if (! $isFreeAction) {
            foreach ($skillCooldowns as $sid => $remaining) {
                $skillCooldowns[$sid] = max(0, $remaining - 1);
            }
            $battle->skill_cooldowns_json = $skillCooldowns;
        }

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
            $characterSkill = $character->skills()->where('skill_id', $skill->id)->first();
            abort_unless($characterSkill, 422, 'Skill not unlocked.');
            $roundsLeft = $skillCooldowns[$skill->id] ?? 0;
            abort_if($roundsLeft > 0, 422, "{$skill->name} is on cooldown for {$roundsLeft} more round".($roundsLeft > 1 ? 's' : '').'.');
            abort_if($character->mana < $skill->mp_cost, 422, 'Not enough mana.');

            $character->decrement('mana', $skill->mp_cost);
            $characterSkill->increment('times_used');
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
            abort_if($item->type === 'pet_food', 422, 'Feed this to a companion from the Companions page, not mid-battle.');
            $healHpPct = $item->stat_json['heal_hp_pct'] ?? 0;
            $healMpPct = $item->stat_json['heal_mp_pct'] ?? 0;
            $healHpFlat = $item->stat_json['heal_hp_flat'] ?? 0;
            $healMpFlat = $item->stat_json['heal_mp_flat'] ?? 0;
            $atkPctBuff = $item->stat_json['atk_pct_buff'] ?? 0;
            $healed = (int) round($stats['eff_hp_max'] * $healHpPct / 100) + (int) $healHpFlat;
            $healedMp = (int) round($stats['eff_mp_max'] * $healMpPct / 100) + (int) $healMpFlat;
            $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $healed);
            if ($healedMp > 0) {
                $character->mana = min($stats['eff_mp_max'], $character->mana + $healedMp);
                $character->save();
            }
            if ($atkPctBuff > 0) {
                $character->atk_buff_pct = $atkPctBuff;
                $character->atk_buff_fights_left = $item->stat_json['buff_fights'] ?? 1;
                $character->save();
            }

            $inventory->decrement('qty');
            if ($inventory->qty <= 0) {
                $inventory->delete();
            }
            $healText = array_filter([$healed > 0 ? "{$healed} HP" : null, $healedMp > 0 ? "{$healedMp} MP" : null]);
            $log[] = "Used {$item->name}, restored ".implode(' and ', $healText).'.'.($atkPctBuff > 0 ? " +{$atkPctBuff}% ATK for your next {$character->atk_buff_fights_left} fights." : '');
        } else {
            abort(422, 'Unknown action.');
        }

        if ($playerDmg > 0) {
            // Counted BEFORE applyPlayerDamage mutates hp, so an AOE hit's lifesteal is based on total
            // damage dealt across every living target it actually hit, not just the primary monster.
            $targetsHit = 1 + ($isAoe ? $battle->battleMonsters->where('hp', '>', 0)->count() : 0);

            $log = $this->applyPlayerDamage($battle, $playerDmg, $isAoe, $type, $weaponRow, $targetMonsterId, $log);
            $this->decayGear($weaponRow);

            $lifestealPct = $stats['lifesteal_pct'] ?? 0;
            if ($lifestealPct > 0) {
                $lifestealHealed = (int) round($playerDmg * $targetsHit * $lifestealPct / 100);
                if ($lifestealHealed > 0) {
                    $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $lifestealHealed);
                    $log[] = "Lifesteal restores {$lifestealHealed} HP.";
                }
            }
        }

        if ($battle->battleCompanions->isNotEmpty()) {
            $log = $this->applyCompanionAttacks($battle, $targetMonsterId, $log);
        }

        if ($this->allEnemiesDefeated($battle)) {
            return $this->resolveWin($battle, $character, $log);
        }

        // Using an item (potion/elixir) or a heal skill is a free action — it doesn't end your turn, so
        // the enemy never gets a free swing off the back of healing up. Attacks and damage skills still
        // pass the turn as normal below.
        if ($isFreeAction) {
            $battle->log_json = $log;
            $battle->save();

            $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

            return [
                'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
                'result' => null,
                'character' => $freshCharacter,
                'stats' => $freshCharacter->effectiveStats(),
            ];
        }

        $hasAdds = $battle->battleMonsters->isNotEmpty();
        if ($this->rollPercent($stats['dodge_chance'] ?? 0)) {
            $log[] = $hasAdds ? 'You dodge the incoming attacks!' : "You dodge {$monster->name}'s attack!";
            $battle->log_json = $log;
            $battle->save();

            $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

            return [
                'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
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
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
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

    /** Every living tamed companion attacks the same target the player just did (or the primary monster
     * if that target's already dead/no target was given) — mirrors applyPlayerDamage's single-target
     * resolution, but companions never have AOE abilities. Returns the updated log array. */
    private function applyCompanionAttacks(Battle $battle, ?int $targetMonsterId, array $log): array
    {
        foreach ($battle->battleCompanions as $bc) {
            if ($bc->hp <= 0) {
                continue;
            }

            $companion = $bc->tamedCompanion;
            $dmg = max(1, (int) round($companion->effectiveAtk() * $this->rand(0.8, 1.2)));

            $target = $targetMonsterId ? $battle->battleMonsters->firstWhere('id', $targetMonsterId) : null;
            if ($target && $target->hp > 0) {
                $target->hp = max(0, $target->hp - $dmg);
                $target->save();
                $hitName = $target->monster->name;
            } else {
                $battle->monster_hp = max(0, $battle->monster_hp - $dmg);
                $hitName = $battle->monster->name;
            }

            $log[] = "{$companion->name} hits {$hitName} for {$dmg}.";
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
                // Damage is computed against the player's own defense exactly as if there were no
                // companions at all — the redirect split (applyRedirectedDamage) happens AFTER, on the
                // post-armor total, not before.
                $enemyDmg = 0;
                for ($i = 0; $i < $hits; $i++) {
                    $enemyDmg += max(2, (int) round($monster->atk * $gradeAtkMult * ($ability['dmg_mult'] ?? 1.0) * $this->rand(0.7, 1.3) - $stats['eff_def'] * 0.25));
                }
                [$playerDmg, $petNote] = $this->applyRedirectedDamage($battle, $enemyDmg);
                if (($ability['key'] ?? null) === 'basic_attack') {
                    $log[] = "{$monster->name} hits you for {$playerDmg}.{$petNote}";
                } else {
                    $hitNote = $hits > 1 ? " ({$hits} hits)" : '';
                    $log[] = "{$monster->name} uses {$ability['name']} for {$playerDmg}{$hitNote} on you.{$petNote}";
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
            [$addPlayerDmg, $addPetNote] = $this->applyRedirectedDamage($battle, $addDmg);
            $log[] = "{$extra->monster->name} hits you for {$addPlayerDmg}.{$addPetNote}";
        }

        return $log;
    }

    /** Splits one already-armor-mitigated hit between the player and every active, living companion —
     * each pet redirects a flat GM-tunable % of the hit onto itself (companion_damage_redirect_pct_per_
     * pet, default 10%), stacking per pet up to a safety cap (companion_damage_redirect_pct_cap, default
     * 90%) so the player always still takes something. The redirected total is split evenly (remainder
     * to the first few pets) and taken exactly as-is — deliberately NOT further reduced by the pet's own
     * Defense, since layering that mitigation on top of an already-small shard made most hits vanish to
     * 0 before a pet ever felt them, which undercut the whole "-10% per pet" deal. Defense still matters
     * for direct hits from other mechanics, just not this split. A pet whose share downs it goes through
     * downCompanion() (revive-eligible) rather than an immediate kill. Returns [playerDamageTaken,
     * logSuffix] — logSuffix is '' when there's nothing to report. */
    private function applyRedirectedDamage(Battle $battle, int $dmg): array
    {
        $eligible = $battle->battleCompanions->where('hp', '>', 0)->values();
        if ($eligible->isEmpty()) {
            $battle->character_hp = max(0, $battle->character_hp - $dmg);

            return [$dmg, ''];
        }

        $pctPerPet = GameConfig::number('companion_damage_redirect_pct_per_pet', 10);
        $cap = GameConfig::number('companion_damage_redirect_pct_cap', 90);
        $redirectPct = min($cap, $eligible->count() * $pctPerPet);
        $redirectedTotal = (int) round($dmg * $redirectPct / 100);
        $playerDmg = $dmg - $redirectedTotal;
        $battle->character_hp = max(0, $battle->character_hp - $playerDmg);

        $baseShare = intdiv($redirectedTotal, $eligible->count());
        $remainder = $redirectedTotal % $eligible->count();
        $notes = [];

        foreach ($eligible as $i => $defender) {
            $petDmg = $baseShare + ($i < $remainder ? 1 : 0);
            if ($petDmg <= 0) {
                continue;
            }

            $companion = $defender->tamedCompanion;
            $defender->hp = max(0, $defender->hp - $petDmg);
            $defender->save();

            if ($defender->hp <= 0) {
                $this->downCompanion($defender);
                $notes[] = "{$companion->name} goes down absorbing {$petDmg}";
            } else {
                $companion->current_hp = $defender->hp;
                $companion->save();
                $notes[] = "{$companion->name} absorbs {$petDmg}";
            }
        }

        return [$playerDmg, $notes ? ' ('.implode(', ', $notes).')' : ''];
    }

    /** A companion reaching 0 hp in battle no longer dies outright — it goes "downed" (see
     * TamedCompanion::is_downed) and sits out the rest of this fight and any future one until revived
     * from the Companions page (TamedCompanionController::revive) with a Pet Revive Potion, or archived
     * for good after companion_revive_max_attempts failed tries. If revives are disabled entirely
     * (GM sets max attempts to 0), it archives immediately exactly like the old behavior. */
    private function downCompanion(BattleCompanion $defender): void
    {
        $defender->defeated_at = now();
        $defender->save();

        $companion = $defender->tamedCompanion;
        if (GameConfig::number('companion_revive_max_attempts', 2) <= 0) {
            $companion->archive('died');

            return;
        }

        $companion->is_downed = true;
        $companion->current_hp = 0;
        $companion->save();
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
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
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
        $zonePenaltyPct = Zone::frontierPenaltyPct($character, $monster->zone);
        $zoneMult = 1 - $zonePenaltyPct / 100;

        $goldGain = (int) round($allMonsters->sum('gold') * $goldMult * $gradeRewardMult * $zoneMult * (1 + $luckBonus + $vipGoldXpBonus + $guildXpBonus + $guildGoldFindBonus));
        $xpGain = (int) round($allMonsters->sum('xp') * $xpMult * $gradeRewardMult * $zoneMult * (1 + ($luckBonus * $xpFactor) + $petXpBonus + $vipGoldXpBonus + $guildXpBonus + $guildXpUpgradeBonus));
        $gemGain = (int) round($allMonsters->sum('gems') * $gemMult * $gradeRewardMult * $zoneMult * (1 + ($luckBonus * $gemFactor)));

        // Reward breakdown for result popup
        $baseGold = (int) round($allMonsters->sum('gold'));
        $baseXp = (int) round($allMonsters->sum('xp'));
        $baseGems = (int) round($allMonsters->sum('gems'));

        $rewardBreakdown = [
            'gold' => [
                'base' => $baseGold,
                'grade_mult' => $gradeRewardMult,
                'luck_pct' => (int) round($luckBonus * 100),
                'vip_pct' => (int) round($vipGoldXpBonus * 100),
                'guild_pct' => (int) round(($guildXpBonus + $guildGoldFindBonus) * 100),
                'zone_penalty_pct' => (int) round($zonePenaltyPct),
                'total' => $goldGain,
            ],
            'xp' => [
                'base' => $baseXp,
                'grade_mult' => $gradeRewardMult,
                'luck_pct' => (int) round($luckBonus * $xpFactor * 100),
                'vip_pct' => (int) round($vipGoldXpBonus * 100),
                'guild_pct' => (int) round(($guildXpBonus + $guildXpUpgradeBonus) * 100),
                'pet_pct' => (int) round($petXpBonus * 100),
                'zone_penalty_pct' => (int) round($zonePenaltyPct),
                'total' => $xpGain,
            ],
        ];
        if ($gemGain > 0) {
            $rewardBreakdown['gems'] = [
                'base' => $baseGems,
                'grade_mult' => $gradeRewardMult,
                'luck_pct' => (int) round($luckBonus * $gemFactor * 100),
                'zone_penalty_pct' => (int) round($zonePenaltyPct),
                'total' => $gemGain,
            ];
        }

        $character->decrementAtkBuffFight();
        $character->increment('gold', $goldGain);
        $character->user->increment('gems', $gemGain);
        if ($gemGain > 0) {
            GemLedger::log($character->user, $gemGain, "battle_win:{$monster->key}", $character);
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
        // Battle Pass xp deliberately does NOT come from combat anymore — it's quest-based only (see
        // QuestController::claim), so grinding/auto-battling a high-xp zone can no longer max the whole
        // season pass by itself the way the old uncapped 0.3x-of-battle-xp hook allowed.
        $this->grantPartyShare($character, $goldGain, $xpGain, $gemGain);

        foreach ($petResults as $petResult) {
            $log[] = "{$petResult['name']} gained companion XP.".($petResult['leveled_up'] ? " Now level {$petResult['level']}!" : '');
        }
        $petFoodDropped = $this->maybeDropPetFood($character, $monster, $log);
        $battle->update(['status' => 'won', 'log_json' => $log]);

        $this->quests->progress($character, 'battles_won', $monster);
        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill', 'user']);
        $newAchievements = $this->achievements->check($freshCharacter, $monster->is_boss ? $monster : null);

        return [
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
            'result' => [
                'outcome' => 'won',
                'gold' => $goldGain,
                'xp' => $xpGain,
                'gems' => $gemGain,
                'breakdown' => $rewardBreakdown,
                'leveled_up' => $leveledUp,
                'pets' => $petResults,
                'pet_food_dropped' => $petFoodDropped,
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
            $maxLevel = $pet->maxLevel();
            if ($xpGain <= 0 || $pet->level >= $maxLevel) {
                continue;
            }

            $xp = $pet->xp + $xpGain;
            $level = $pet->level;
            $leveledUp = false;

            $xpMax = CharacterPet::xpForLevel($level);
            while ($level < $maxLevel && $xp >= $xpMax) {
                $xp -= $xpMax;
                $level++;
                $leveledUp = true;
                $xpMax = CharacterPet::xpForLevel($level);
            }
            if ($level >= $maxLevel) {
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
            ->with('character.user')
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
                $partner->user->increment('gems', $gemShare);
                GemLedger::log($partner->user, $gemShare, "party_share:{$character->name}", $partner);
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
        $character->decrementAtkBuffFight();
        $character->save();
        $character->increment('battles_lost');
        $battle->character_hp = $reviveHp;

        if ($penalty['levels_lost'] > 0) {
            $log[] = "Dropped to level {$character->level}! Lost ".($penalty['levels_lost'] * 3)." attribute pts and {$penalty['levels_lost']} skill pts.";
        }
        $battle->update(['status' => 'lost', 'log_json' => $log]);

        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

        return [
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
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
        $baseLossPct = GameConfig::number('death_xp_loss_pct', 5);

        $goldLost = (int) min($character->gold, round($character->gold * $goldLossPct / 100));

        $currentLevelXp = Character::xpForLevel($character->level);
        $previousLevelXp = Character::xpForLevel(max(1, $character->level - 1));

        // How much XP the player has earned within this level
        $xpProgressInLevel = max(0, $character->xp - $previousLevelXp);

        // Loss: 5% of YOUR PROGRESS in this level + flat amount
        $percentageLoss = (int) round($xpProgressInLevel * $baseLossPct / 100);
        $flatLoss = (int) round(100 * (($character->level / 100) + 1));
        $xpLost = $percentageLoss + $flatLoss;

        $level = $character->level;
        $xp = $character->xp - $xpLost;
        $levelsLost = 0;

        // Delevel if XP drops below what's needed for current level
        while ($level > 1 && $xp < Character::xpForLevel($level - 1)) {
            $level--;
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
        $oldLevel = $character->level;
        $attrPoints = $character->attribute_points;
        $skillPoints = $character->skill_points;

        // Calculate correct level from cumulative XP
        $level = 1;
        while ($level < Character::MAX_LEVEL && $xp >= Character::xpForLevel($level)) {
            $level++;
        }

        $levelsGained = $level - $oldLevel;
        if ($levelsGained > 0) {
            $attrPoints += $levelsGained * 3;
            $skillPoints += $levelsGained;
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

    /** Chance to grant one Pet Food on a battle win — the only way to level a tamed companion (see
     * TamedCompanionController::feed). Scales up with the zone's danger (see Zone::petFoodBonusPct) so
     * grinding higher zones is the real source of pet food, not a flat rate everywhere. Appends a log
     * line by reference (resolveWin() already has a $log array in flight at the call site) and also
     * returns the item name so the result payload can show a proper reward chip — the log line alone is
     * invisible in compact-battle-log mode. */
    private function maybeDropPetFood(Character $character, Monster $monster, array &$log): ?string
    {
        $chance = GameConfig::number('pet_food_drop_chance_pct', 15) + Zone::petFoodBonusPct($monster->zone?->danger);
        if (! $this->rollPercent($chance)) {
            return null;
        }

        $item = Item::where('type', 'pet_food')->first();
        if (! $item) {
            return null;
        }

        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + 1;
        $inventory->save();

        $log[] = "{$item->name} dropped!";

        return $item->name;
    }

    /** % penalty applied to a tame roll for a monster in a dangerous zone — same $prefix pattern feeds
     * both the eligibility roll ('tame_eligibility') and the success roll ('tame_success'), each GM-tunable
     * independently via GmConfigController. */
    private function tameZonePenalty(Monster $monster, string $prefix): float
    {
        return match ($monster->zone?->danger) {
            'high' => GameConfig::number("{$prefix}_zone_high_penalty_pct", 10),
            'deadly' => GameConfig::number("{$prefix}_zone_deadly_penalty_pct", 20),
            default => 0,
        };
    }

    /**
     * Attempts to tame the battle's target once it's ≤10% hp — 1v1 fights only. Two independent rolls:
     * (1) whether the target is tameable at all this fight, cached on the battle so repeated attempts
     * don't re-roll it (a "no" costs nothing — it's a free check, not an attempt); (2) if eligible,
     * whether the attempt itself succeeds, boosted by Luck + VIP. A failed attempt-2 consumes the turn
     * exactly like a whiffed attack. Success ends the battle with the monster captured — no kill rewards,
     * since it was caught rather than killed.
     */
    public function attemptTame(Battle $battle, Character $character): array
    {
        abort_if($battle->status !== 'active', 422, 'Battle already finished.');
        abort_if($battle->battleMonsters->isNotEmpty(), 422, 'Taming only works in 1v1 fights.');

        $this->regenInBattle($battle, $character);

        $monster = $battle->monster;
        abort_if($monster->is_boss, 422, 'Bosses cannot be tamed.');

        $hpPct = $battle->monster_hp_max ? $battle->monster_hp / $battle->monster_hp_max : 1;
        abort_if($hpPct > 0.10, 422, 'This enemy is still too strong to tame — wear it down first.');

        // Taming a zone's monsters only unlocks once the player has meaningfully outgrown that zone,
        // not the moment they can first walk into it.
        $unlockLevel = ($monster->zone?->min_level ?? 1) + 10;
        abort_if($character->level < $unlockLevel, 422, "You need to be level {$unlockLevel} to tame monsters from this zone.");

        $user = $character->user;
        $rosterCount = TamedCompanion::where('character_id', $character->id)->whereNull('archived_at')->count();
        abort_if($rosterCount >= $user->tameRosterCap(), 422, 'Your companion roster is full — release one from the Companions page first.');

        $log = $battle->log_json ?? [];

        if ($battle->tame_eligible === null) {
            $chance = GameConfig::number('tame_eligibility_base_pct', 40)
                - ($monster->is_elite ? GameConfig::number('tame_eligibility_elite_penalty_pct', 20) : 0)
                - $this->tameZonePenalty($monster, 'tame_eligibility');
            $battle->tame_eligible = $this->rollPercent(max(5, min(95, $chance)));
            $battle->save();
        }

        if (! $battle->tame_eligible) {
            $log[] = "{$monster->name} was unable to tame.";
            $battle->log_json = $log;
            $battle->save();

            return $this->continuingBattlePayload($battle, $character);
        }

        $stats = $character->effectiveStats();
        $luck = max(0, (int) ($stats['luck'] ?? 0));
        $luckBonus = min(
            GameConfig::number('luck_tame_bonus_cap', 30),
            $luck * GameConfig::number('luck_tame_bonus_per_point', 1)
        );
        $successChance = GameConfig::number('tame_success_base_pct', 50)
            + $luckBonus
            + $user->vipTameBonus()
            - ($monster->is_elite ? GameConfig::number('tame_success_elite_penalty_pct', 20) : 0)
            - $this->tameZonePenalty($monster, 'tame_success');

        if ($this->rollPercent(max(5, min(95, $successChance)))) {
            // Only auto-activates if there's a free active slot — a level-gated cap below the roster
            // size (see User::activeTameSlots) means a freshly-tamed companion can land benched, same
            // as PetController::activate rejects rather than silently swapping out an existing one.
            $activeCount = TamedCompanion::where('character_id', $character->id)->whereNull('archived_at')->where('active', true)->count();
            // Base stats are snapshotted at the encounter's own grade multiplier (see GradeService) —
            // a companion tamed from a Champion/Legendary roll is a genuinely stronger catch, not just a
            // cosmetically different tag, same as that grade made the live monster hit harder in the fight.
            $companion = TamedCompanion::create([
                'character_id' => $character->id,
                'monster_id' => $monster->id,
                'name' => $monster->name,
                'glyph' => $monster->glyph,
                'is_elite' => $monster->is_elite,
                'grade' => $battle->grade,
                'base_hp' => (int) round($monster->hp * $this->grades->hpMult($battle->grade)),
                'base_atk' => (int) round($monster->atk * $this->grades->atkMult($battle->grade)),
                'level' => 1,
                'xp' => 0,
                'current_hp' => 0,
                'last_regen_at' => now(),
                'active' => $activeCount < $user->activeTameSlots($character),
            ]);
            $companion->current_hp = $companion->effectiveHpMax();
            $companion->save();
            TamedCompanionLog::log($companion, 'captured');

            $log[] = "You tamed {$monster->name}!";
            $battle->update(['status' => 'tamed', 'log_json' => $log]);

            $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

            return [
                'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
                'result' => [
                    'outcome' => 'tamed',
                    'companion' => $companion,
                    'character' => $freshCharacter,
                    'stats' => $freshCharacter->effectiveStats(),
                ],
            ];
        }

        $log[] = 'Taming attempt failed!';
        $armorRow = $this->equippedGear($character, 'armor');
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

        return $this->continuingBattlePayload($battle, $character);
    }

    /** The "nothing decisive happened, battle continues" response shape shared by act()'s free-action/
     * dodge/normal-round returns and attemptTame()'s ineligible/failed-attempt returns. */
    private function continuingBattlePayload(Battle $battle, Character $character): array
    {
        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

        return [
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
            'result' => null,
            'character' => $freshCharacter,
            'stats' => $freshCharacter->effectiveStats(),
        ];
    }

}
