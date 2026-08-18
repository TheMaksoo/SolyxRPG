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
        private MonsterAiService $monsterAi = new MonsterAiService(new StatusEffectService()),
        private QuestService $quests = new QuestService(),
        private StatusEffectService $statuses = new StatusEffectService(),
        private ThreatService $threats = new ThreatService(),
    ) {}

    /** $extraMonsters spawns additional "adds" fighting alongside $monster — a multi-enemy boss encounter
     * instead of the usual 1v1 (see DungeonService). Adds only ever basic-attack; $monster keeps the full
     * ability/cooldown AI regardless of how many adds are alongside it. */
    public function start(Character $character, Monster $monster, string $grade = 'common', array $extraMonsters = []): Battle
    {
        $stats = $character->effectiveStats();
        $startingHp = (int) min($stats['eff_hp_max'], max(1, $character->hp));
        // A monster's role build (see MonsterAiService::ROLE_BUILD) scales its actual spawned HP up or
        // down from its seeded base — a `tank` rolls in noticeably beefier, a `caster`/`buffer` noticeably
        // squishier, on top of the usual grade roll.
        $monsterHp = (int) round($monster->hp * $this->grades->hpMult($grade) * $this->monsterAi->buildFor($monster->role)['hp_mult']);

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
            $hp = (int) round($extra->hp * $this->grades->hpMult($grade) * $this->monsterAi->buildFor($extra->role)['hp_mult']);
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

    /** Rolls whether a zone `walk()` encounter should be a multi-enemy "pack" instead of a lone monster —
     * only possible from the 2nd zone onward (Zone::danger other than 'safe'), with both the roll chance
     * and the resulting group size scaling up with that zone's danger tier (see GmConfigController's
     * pack_encounter_chance_pct_{danger} / pack_group_size_{min,max}_{danger} keys).
     *
     * On top of the danger-tier base, farming a zone behind the character's own frontier (see
     * Zone::frontierPenaltyPct() — the same "penalty zone" already docked off gold/xp for outleveled
     * farming) ALSO raises both the pack chance and the group-size ceiling: the bigger that reward
     * penalty, the bigger the groups get, so trivializing an easy zone by overleveling it doesn't stay
     * purely-easy-and-purely-worse-rewards forever — it gets genuinely more crowded too.
     *
     * Returns the extra monsters to spawn alongside $primary — empty for a normal 1v1 walk, exactly like
     * before either of these existed. */
    public function rollPackMonsters(Character $character, Zone $zone, Monster $primary): array
    {
        if (! in_array($zone->danger, ['medium', 'high', 'deadly'], true)) {
            return [];
        }

        $penaltyPct = Zone::frontierPenaltyPct($character, $zone);

        $chance = GameConfig::number("pack_encounter_chance_pct_{$zone->danger}", 20)
            + $penaltyPct * GameConfig::number('pack_penalty_chance_scalar', 0.5);
        if (! $this->rollPercent(min(100, $chance))) {
            return [];
        }

        $min = (int) GameConfig::number("pack_group_size_min_{$zone->danger}", 2);
        $max = max($min, (int) GameConfig::number("pack_group_size_max_{$zone->danger}", 2));
        $max += (int) floor($penaltyPct / GameConfig::number('pack_penalty_pct_per_extra_size', 25));
        $extraCount = mt_rand($min, $max) - 1;
        if ($extraCount <= 0) {
            return [];
        }

        return Monster::where('enabled', true)
            ->where('is_boss', false)
            ->where('zone_id', $zone->id)
            ->where('min_level', '<=', $character->level)
            ->where('id', '!=', $primary->id)
            ->inRandomOrder()
            ->limit($extraCount)
            ->get()
            ->all();
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
        // Structured, per-action breakdown of this round for the frontend's animated turn-by-turn
        // playback (BattlePage.vue) — one entry per damage/heal/status/stun moment, in the exact order
        // they happened, alongside (not replacing) the existing flat-text $log. Purely additive
        // instrumentation: nothing here feeds back into any damage/reward calculation.
        $events = [];
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
        $crit = false;
        // Multi-hit skills (e.g. Chain Cast's `hits` => 2) land as that many separate, independently-
        // animated hits instead of one lumped number — see the split below, right before applyPlayerDamage.
        $hits = 1;
        // Total damage this action actually dealt — stays 0 for a pure-support/heal skill. Used as the
        // basis for any `magnitude_pct_of_damage` status (e.g. Burn for "25% of the damage dealt").
        $totalDealt = 0;
        $cleave = false;
        $cleaveTargetId = $targetMonsterId;

        // Status-effect modifiers on the player (see StatusEffectService) — dmg_dealt_mult reflects any
        // active `weaken` stacks, crit_delta any active `crit_down` (e.g. from an enemy debuff). Both are
        // 1/0 no-ops when nothing's applied, so this changes nothing for a fight with no active statuses.
        $playerMods = $this->statuses->modifiers($battle, 'player', null);

        if ($type === 'attack') {
            $playerDmg = (int) round($stats['eff_atk'] * $playerMods['dmg_dealt_mult'] * $this->rand(0.8, 1.2));
            if ($this->rollPercent(max(0, $stats['crit_chance'] - $playerMods['crit_delta']))) {
                $playerDmg = (int) round($playerDmg * $stats['crit_damage_mult']);
                $log[] = 'Critical hit!';
                $crit = true;
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
                $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => 'player', 'target_id' => null, 'type' => 'heal', 'amount' => $healed];
                $this->threats->addHealThreatToAllEnemies($battle, 'player', null, $healed);
            } else {
                $mult = $this->skills->damageMultiplier($skill, $characterSkill->level);
                $playerDmg = (int) round($stats['eff_atk'] * $mult * $playerMods['dmg_dealt_mult'] * $this->rand(0.85, 1.15));
                $isAoe = (bool) ($skill->effect_json['aoe'] ?? false);
                // Multi-hit skills (e.g. Chain Cast's `hits` => 2) land as that many separate hits below
                // rather than one lumped number — matches what the skill's own name/description promises.
                $hits = max(1, $skill->effect_json['hits'] ?? 1);
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
            if ($healed > 0) {
                $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => 'player', 'target_id' => null, 'type' => 'heal', 'amount' => $healed];
                $this->threats->addHealThreatToAllEnemies($battle, 'player', null, $healed);
            }
        } else {
            abort(422, 'Unknown action.');
        }

        if ($playerDmg > 0) {
            // Counted BEFORE applyPlayerDamage mutates hp, so an AOE hit's lifesteal is based on total
            // damage dealt across every living target it actually hit, not just the primary monster.
            $targetsHit = 1 + ($isAoe ? $battle->battleMonsters->where('hp', '>', 0)->count() : 0);

            // A multi-hit skill's already-rolled total is split evenly across $hits separate
            // applyPlayerDamage calls (remainder to the first hit) rather than applied as one lump —
            // each lands as its own animated hit/event, matching the skill's name (e.g. Chain Cast
            // "hits twice"). Overall damage output is unchanged from a single call with the full amount.
            //
            // A `cleave` skill (e.g. Chain Cast — "chain" implies jumping between targets, not double-
            // hitting the same one) retargets mid-sequence: if a hit finishes off the current target,
            // remaining hits carry over to another living enemy instead of piling onto a corpse. In a
            // lone-enemy fight this is a no-op (there's nowhere else to cleave to) — it only actually
            // spreads once a pack fight has more than one target alive.
            $cleave = ! $isAoe && (bool) ($skill?->effect_json['cleave'] ?? false);
            $baseShare = intdiv($playerDmg, $hits);
            $remainder = $playerDmg % $hits;
            $totalDealt = 0;
            for ($h = 0; $h < $hits; $h++) {
                $hitDmg = $baseShare + ($h < $remainder ? 1 : 0);
                if ($hitDmg <= 0) {
                    continue;
                }
                if ($cleave && ! $this->targetIsAlive($battle, $cleaveTargetId)) {
                    $next = $this->nextLivingMonsterTarget($battle);
                    if ($next === false) {
                        break;
                    }
                    $cleaveTargetId = $next;
                }
                [$log, $events] = $this->applyPlayerDamage($battle, $hitDmg, $isAoe, $type, $weaponRow, $cleave ? $cleaveTargetId : $targetMonsterId, $log, $events, $crit);
                $totalDealt += $hitDmg;
            }
            $this->decayGear($weaponRow);

            $lifestealPct = $stats['lifesteal_pct'] ?? 0;
            if ($lifestealPct > 0) {
                $lifestealHealed = (int) round($totalDealt * $targetsHit * $lifestealPct / 100);
                if ($lifestealHealed > 0) {
                    $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $lifestealHealed);
                    $log[] = "Lifesteal restores {$lifestealHealed} HP.";
                    $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => 'player', 'target_id' => null, 'type' => 'heal', 'amount' => $lifestealHealed];
                }
            }
        }

        // Skill-driven buffs/debuffs (see StatusEffectService + Skill::effect_json['applies_status']) —
        // resolved independently of whether the skill also dealt damage, since a pure-support skill (e.g.
        // Bonded Guard) has dmg_mult 0 and never reaches the block above at all. A `cleave` skill's debuff
        // follows the same retargeted enemy its last hit actually landed on, not the original target —
        // otherwise Thousand Cuts' Weaken could land on the corpse it just carved through instead of the
        // enemy its hits actually ended on.
        if ($skill && ! empty($skill->effect_json['applies_status'])) {
            $statusTargetId = $cleave ? $cleaveTargetId : $targetMonsterId;
            [$log, $events] = $this->applySkillStatuses($battle, $skill, $this->resolveTargetedMonsters($battle, $isAoe, $statusTargetId), $log, $events, $totalDealt, $characterSkill->level ?? 1);
        }

        if ($this->allEnemiesDefeated($battle)) {
            $result = $this->resolveWin($battle, $character, $log);
            $result['events'] = $events;

            return $result;
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
                'events' => $events,
            ];
        }

        // Everyone else's turn for the rest of this round (companions and enemies interleaved by real
        // speed, not a fixed group order — see resolveRestOfRound), then into fresh rounds as needed,
        // until it's the player's turn again. Dodge is now rolled per individual attack inside that walk
        // (see resolveMonsterAction) rather than once for the whole round.
        [$log, $events] = $this->resolveRestOfRound($battle, $stats, $armorRow, $targetMonsterId, $log, $events);

        if ($this->allEnemiesDefeated($battle)) {
            $result = $this->resolveWin($battle, $character, $log);
            $result['events'] = $events;

            return $result;
        }

        if ($battle->character_hp <= 0) {
            if (! empty($stats['has_undying']) && ! $battle->revived_with_skill) {
                $battle->character_hp = 1;
                $battle->revived_with_skill = true;
                $log[] = 'Undying triggers! You survive with 1 HP.';
                $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => 'player', 'target_id' => null, 'type' => 'undying'];
            } else {
                $result = $this->resolveLoss($battle, $character, $log, $stats);
                $result['events'] = $events;

                return $result;
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
            'events' => $events,
        ];
    }

    /** Applies the player's already-rolled damage to the right target(s) and appends the matching log line(s).
     * AOE hits the primary plus every living add for the same amount; single-target hits $targetMonsterId's
     * add if it's alive, else falls back to the primary — a stale/invalid target never errors, it just
     * redirects to the boss. Returns the updated log array. */
    /** @return array{0: array, 1: array} [updated log, updated events] */
    private function applyPlayerDamage(Battle $battle, int $dmg, bool $isAoe, string $type, ?Inventory $weaponRow, ?int $targetMonsterId, array $log, array $events, bool $crit = false): array
    {
        $monster = $battle->monster;

        if ($isAoe) {
            $hitNotes = [];
            $mainDmg = $this->finalizeDamageToTarget($battle, 'monster', 0, $dmg);
            $battle->monster_hp = max(0, $battle->monster_hp - $mainDmg);
            $hitNotes[] = "{$monster->name} for {$mainDmg}";
            $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => 'monster', 'target_id' => 0, 'type' => 'damage', 'amount' => $mainDmg, 'crit' => $crit];
            $this->threats->addDamageThreat($battle, 'monster', 0, 'player', null, $mainDmg);
            foreach ($battle->battleMonsters as $extra) {
                if ($extra->hp <= 0) {
                    continue;
                }
                $extraDmg = $this->finalizeDamageToTarget($battle, 'monster', $extra->id, $dmg);
                $extra->hp = max(0, $extra->hp - $extraDmg);
                $extra->save();
                $hitNotes[] = "{$extra->monster->name} for {$extraDmg}";
                $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => 'monster', 'target_id' => $extra->id, 'type' => 'damage', 'amount' => $extraDmg, 'crit' => $crit];
                $this->threats->addDamageThreat($battle, 'monster', $extra->id, 'player', null, $extraDmg);
            }
            $log[] = 'You hit '.implode(', ', $hitNotes).'.';

            return [$log, $events];
        }

        $target = $targetMonsterId ? $battle->battleMonsters->firstWhere('id', $targetMonsterId) : null;
        if ($target && $target->hp > 0) {
            $dmg = $this->finalizeDamageToTarget($battle, 'monster', $target->id, $dmg);
            $target->hp = max(0, $target->hp - $dmg);
            $target->save();
            $hitName = $target->monster->name;
            $hitTargetId = $target->id;
        } else {
            $dmg = $this->finalizeDamageToTarget($battle, 'monster', 0, $dmg);
            $battle->monster_hp = max(0, $battle->monster_hp - $dmg);
            $hitName = $monster->name;
            $hitTargetId = 0;
        }
        $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => 'monster', 'target_id' => $hitTargetId, 'type' => 'damage', 'amount' => $dmg, 'crit' => $crit];
        $this->threats->addDamageThreat($battle, 'monster', $hitTargetId, 'player', null, $dmg);

        if ($type === 'attack') {
            $verb = self::ATTACK_VERBS[$weaponRow?->item->weapon_category] ?? 'hit';
            $log[] = "You {$verb} {$hitName} for {$dmg}.";
        } else {
            $log[] = "You hit {$hitName} for {$dmg}.";
        }

        return [$log, $events];
    }

    /** Applies a defending target's `vulnerable` (dmg_taken_mult) status and any active `shield` stacks
     * to a raw hit before it touches HP — shared by every damage-application site (player, companion,
     * and monster attacks alike) so a target's defensive statuses always behave the same way regardless
     * of who's hitting it. */
    private function finalizeDamageToTarget(Battle $battle, string $targetType, ?int $targetId, int $rawDmg): int
    {
        $mods = $this->statuses->modifiers($battle, $targetType, $targetId);
        $buildMult = $targetType === 'monster' ? $this->monsterAi->buildFor($this->monsterForTarget($battle, $targetId)?->role)['dmg_taken_mult'] : 1.0;
        $adjusted = (int) round($rawDmg * $mods['dmg_taken_mult'] * $buildMult);

        return (int) round($this->statuses->consumeShield($battle, $targetType, $targetId, $adjusted));
    }

    /** The live Monster behind a 'monster'-type target id (0 = the primary monster slot, else an "add"),
     * used to look up that monster's role build (see MonsterAiService::ROLE_BUILD) wherever a hit lands
     * on it. */
    private function monsterForTarget(Battle $battle, ?int $targetId): ?Monster
    {
        if (! $targetId) {
            return $battle->monster;
        }

        return $battle->battleMonsters->firstWhere('id', $targetId)?->monster;
    }

    /** Whether the given 'monster'-type target (null/0 = primary) still has HP — used by a `cleave`
     * skill's multi-hit loop to notice mid-sequence that its current target just died. */
    private function targetIsAlive(Battle $battle, ?int $targetId): bool
    {
        if (! $targetId) {
            return $battle->monster_hp > 0;
        }

        return ($battle->battleMonsters->firstWhere('id', $targetId)?->hp ?? 0) > 0;
    }

    /** The next living enemy a `cleave` skill should carry its remaining hits into once its current
     * target is dead — primary first, then any living add. Returns false if nothing is left alive to
     * cleave into (remaining hits are simply dropped rather than misapplied to a corpse). */
    private function nextLivingMonsterTarget(Battle $battle): int|false|null
    {
        if ($battle->monster_hp > 0) {
            return null;
        }

        $extra = $battle->battleMonsters->firstWhere('hp', '>', 0);

        return $extra ? $extra->id : false;
    }

    /** Applies this round's `burn`/`mend_over_time` ticks to every living battle participant, then
     * decrements every status stack's remaining duration (deleting anything that expires). Called once
     * per resolved round, right after the enemy turn — a free action (item/heal skill) that skips the
     * enemy turn doesn't burn a round off active statuses either. */
    /** @return array{0: array, 1: array} [updated log, updated events] */
    private function tickStatusEffects(Battle $battle, array $stats, array $log, array $events): array
    {
        if ($battle->character_hp > 0) {
            // Mend resolves BEFORE burn/poison (opposite of the old order) so regen has a chance to
            // offset the same round's DOT instead of arriving too late to matter — a character who'd
            // net-survive the round shouldn't ever visibly bottom out at 0 HP only to be "revived" by a
            // mend tick that used to resolve after the killing blow. If burn/poison still finishes them
            // off after a real mend, that's a genuine loss, not this ordering issue.
            $mend = $this->statuses->mendAmount($battle, 'player', null);
            if ($mend > 0) {
                $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $mend);
                $log[] = "You regenerate {$mend} HP.";
                $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'player', 'target_id' => null, 'type' => 'heal', 'amount' => $mend];
            }
            $burn = $this->statuses->burnDamage($battle, 'player', null);
            if ($burn > 0 && $battle->character_hp > 0) {
                $battle->character_hp = max(0, $battle->character_hp - $burn);
                $log[] = "You take {$burn} burn damage.";
                $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'player', 'target_id' => null, 'type' => 'damage', 'amount' => $burn, 'crit' => false];
            }
            $poison = $this->statuses->poisonDamage($battle, 'player', null, $stats['eff_hp_max']);
            if ($poison > 0 && $battle->character_hp > 0) {
                $battle->character_hp = max(0, $battle->character_hp - $poison);
                $log[] = "You take {$poison} poison damage.";
                $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'player', 'target_id' => null, 'type' => 'damage', 'amount' => $poison, 'crit' => false];
            }
        }
        $this->statuses->tick($battle, 'player', null);

        if ($battle->monster_hp > 0) {
            $burn = $this->statuses->burnDamage($battle, 'monster', 0);
            if ($burn > 0) {
                $battle->monster_hp = max(0, $battle->monster_hp - $burn);
                $log[] = "{$battle->monster->name} takes {$burn} burn damage.";
                $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'monster', 'target_id' => 0, 'type' => 'damage', 'amount' => $burn, 'crit' => false];
            }
            $poison = $this->statuses->poisonDamage($battle, 'monster', 0, $battle->monster_hp_max ?? $battle->monster->hp);
            if ($poison > 0 && $battle->monster_hp > 0) {
                $battle->monster_hp = max(0, $battle->monster_hp - $poison);
                $log[] = "{$battle->monster->name} takes {$poison} poison damage.";
                $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'monster', 'target_id' => 0, 'type' => 'damage', 'amount' => $poison, 'crit' => false];
            }
        }
        $this->statuses->tick($battle, 'monster', 0);

        foreach ($battle->battleMonsters as $extra) {
            if ($extra->hp > 0) {
                $burn = $this->statuses->burnDamage($battle, 'monster', $extra->id);
                if ($burn > 0) {
                    $extra->hp = max(0, $extra->hp - $burn);
                    $extra->save();
                    $log[] = "{$extra->monster->name} takes {$burn} burn damage.";
                    $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'monster', 'target_id' => $extra->id, 'type' => 'damage', 'amount' => $burn, 'crit' => false];
                }
                $poison = $this->statuses->poisonDamage($battle, 'monster', $extra->id, $extra->hp_max);
                if ($poison > 0 && $extra->hp > 0) {
                    $extra->hp = max(0, $extra->hp - $poison);
                    $extra->save();
                    $log[] = "{$extra->monster->name} takes {$poison} poison damage.";
                    $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'monster', 'target_id' => $extra->id, 'type' => 'damage', 'amount' => $poison, 'crit' => false];
                }
            }
            $this->statuses->tick($battle, 'monster', $extra->id);
        }

        foreach ($battle->battleCompanions as $bc) {
            // Mend resolves BEFORE burn/poison (matching the player block above) so a companion's regen
            // gets a chance to offset the same round's DOT instead of arriving after the killing blow.
            if ($bc->hp > 0) {
                $mend = $this->statuses->mendAmount($battle, 'companion', $bc->id);
                if ($mend > 0) {
                    $bc->hp = min($bc->hp_max, $bc->hp + $mend);
                    $bc->save();
                    $log[] = "{$bc->tamedCompanion->name} regenerates {$mend} HP.";
                    $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'companion', 'target_id' => $bc->id, 'type' => 'heal', 'amount' => $mend];
                }
                $burn = $this->statuses->burnDamage($battle, 'companion', $bc->id);
                if ($burn > 0 && $bc->hp > 0) {
                    $bc->hp = max(0, $bc->hp - $burn);
                    $bc->save();
                    $log[] = "{$bc->tamedCompanion->name} takes {$burn} burn damage.";
                    $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'companion', 'target_id' => $bc->id, 'type' => 'damage', 'amount' => $burn, 'crit' => false];
                }
                $poison = $this->statuses->poisonDamage($battle, 'companion', $bc->id, $bc->hp_max);
                if ($poison > 0 && $bc->hp > 0) {
                    $bc->hp = max(0, $bc->hp - $poison);
                    $bc->save();
                    $log[] = "{$bc->tamedCompanion->name} takes {$poison} poison damage.";
                    $events[] = ['actor' => 'status', 'actor_id' => null, 'target' => 'companion', 'target_id' => $bc->id, 'type' => 'damage', 'amount' => $poison, 'crit' => false];
                }
            }
            $this->statuses->tick($battle, 'companion', $bc->id);
        }

        return [$log, $events];
    }

    /** Which monster(s) a player action is actually aimed at, independent of whether it deals damage —
     * AOE hits the primary plus every living add, single-target hits $targetMonsterId's add if it's
     * alive else falls back to the primary. Used both by applyPlayerDamage and by applySkillStatuses
     * (a pure-support skill with no damage still needs to know its intended enemy target). */
    private function resolveTargetedMonsters(Battle $battle, bool $isAoe, ?int $targetMonsterId): array
    {
        if ($isAoe) {
            $targets = [['type' => 'monster', 'id' => 0]];
            foreach ($battle->battleMonsters as $extra) {
                if ($extra->hp > 0) {
                    $targets[] = ['type' => 'monster', 'id' => $extra->id];
                }
            }

            return $targets;
        }

        $target = $targetMonsterId ? $battle->battleMonsters->firstWhere('id', $targetMonsterId) : null;

        return [['type' => 'monster', 'id' => $target && $target->hp > 0 ? $target->id : 0]];
    }

    /** Resolves every `applies_status` entry on a just-used skill onto its real target(s) — 'enemy' reuses
     * whatever applyPlayerDamage/resolveTargetedMonsters just hit, 'self' is the player, 'companion' is
     * the lowest-HP% living companion (mirrors the fetched design's lowestParty helper), 'party' is the
     * player plus every living companion. */
    /** @return array{0: array, 1: array} [updated log, updated events] */
    private function applySkillStatuses(Battle $battle, Skill $skill, array $enemyTargets, array $log, array $events, int $dealtDamage = 0, int $rank = 1): array
    {
        foreach ($skill->effect_json['applies_status'] as $statusDef) {
            $targets = match ($statusDef['target'] ?? 'enemy') {
                'self' => [['type' => 'player', 'id' => null]],
                'companion' => $this->lowestHpCompanionTarget($battle),
                'party' => array_merge([['type' => 'player', 'id' => null]], $this->allCompanionTargets($battle)),
                default => $enemyTargets,
            };

            // A status can name a flat `magnitude` (as always) OR `magnitude_pct_of_damage` — resolved
            // dynamically from what THIS action actually just dealt (e.g. Burn for "25% of the damage
            // dealt" scales with the caster's real hit instead of a fixed authored number).
            $magnitude = isset($statusDef['magnitude_pct_of_damage'])
                ? $dealtDamage * $statusDef['magnitude_pct_of_damage'] / 100
                : (float) $statusDef['magnitude'];

            // Opt-in rank scaling — most branches' applies_status entries are intentionally flat
            // regardless of rank (e.g. a self-inflicted Vulnerable drawback shouldn't get WORSE the more
            // you rank up the skill that causes it), so a skill has to explicitly ask for its debuff to
            // grow with rank via `scales_with_rank` — see SkillSeeder's Foundation-tree capstones (Void
            // Nova, Thousand Cuts, Rain of Arrows, Sunder), where "more debuffs" IS the whole point of
            // ranking that skill up. Duration scales the same way (rounded, floored at the authored
            // minimum) — a maxed-out debuff skill lasts longer as well as hitting harder, which matters
            // most in pack fights where the extra uptime lets sequential casts overlap into a bigger stack.
            $rounds = (int) $statusDef['rounds'];
            if (! empty($statusDef['scales_with_rank'])) {
                $rankMult = $this->skills->rankMultiplier($rank);
                $magnitude *= $rankMult;
                $rounds = max($rounds, (int) round($rounds * $rankMult));
            }

            foreach ($targets as $t) {
                $this->statuses->apply($battle, $t['type'], $t['id'], $statusDef['effect'], $magnitude, $rounds, $skill->name);
                $log[] = $this->statusLogLine($skill->name, $statusDef, null, $magnitude, $rounds);
                $events[] = ['actor' => 'player', 'actor_id' => null, 'target' => $t['type'], 'target_id' => $t['id'], 'type' => 'status', 'status_key' => $statusDef['effect']];
            }
        }

        return [$log, $events];
    }

    /** $targetLabel disambiguates who the status actually landed on — omitted (the default) for the
     * common "on you" cases (a skill/ability hitting the player) exactly as before; passed for anything
     * that isn't the player (e.g. a taunting companion drawing an enemy's debuff instead). */
    /** $resolvedMagnitude overrides $statusDef['magnitude'] for the displayed number, and $resolvedRounds
     * overrides $statusDef['rounds'] for the displayed duration — needed whenever the real applied amount
     * was computed dynamically (see `magnitude_pct_of_damage` above) or scaled by rank (`scales_with_rank`)
     * rather than read straight off the skill/ability definition. */
    private function statusLogLine(string $sourceName, array $statusDef, ?string $targetLabel = null, ?float $resolvedMagnitude = null, ?int $resolvedRounds = null): string
    {
        $meta = StatusEffectService::CATALOG[$statusDef['effect']] ?? ['label' => $statusDef['effect'], 'unit' => 'flat'];
        $magnitude = $resolvedMagnitude ?? (float) $statusDef['magnitude'];
        $amount = ($this->trimAmount($magnitude)).($meta['unit'] === 'pct' ? '%' : '');
        $rounds = $resolvedRounds ?? (int) $statusDef['rounds'];
        $suffix = $targetLabel ? " on {$targetLabel}" : '';

        return "{$sourceName} applies {$meta['label']} ({$amount}){$suffix} for {$rounds} round".($rounds > 1 ? 's' : '').'.';
    }

    private function trimAmount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /** @return array{0: array{type: string, id: ?int}}|array{} */
    private function lowestHpCompanionTarget(Battle $battle): array
    {
        $lowest = $battle->battleCompanions->where('hp', '>', 0)->sortBy(fn ($bc) => $bc->hp / max(1, $bc->hp_max))->first();

        return $lowest ? [['type' => 'companion', 'id' => $lowest->id]] : [];
    }

    private function allCompanionTargets(Battle $battle): array
    {
        return $battle->battleCompanions->where('hp', '>', 0)->map(fn ($bc) => ['type' => 'companion', 'id' => $bc->id])->values()->all();
    }

    /** One companion's own turn in the real speed-ordered queue (see resolveRestOfRound) — mends the
     * weakest ally if it's mender-sourced and someone's meaningfully hurt, otherwise attacks the same
     * target the player last picked (or the primary monster if that add's dead/no target was given).
     * Companions never have AOE abilities. Skips (no-op) if every enemy is already dead — the player's
     * own hit(s) this turn may have just cleared the fight before the queue even reached this companion.
     *
     * @return array{0: array, 1: array} [updated log, updated events]
     */
    private function resolveCompanionTurn(Battle $battle, array $stats, BattleCompanion $bc, ?int $targetMonsterId, array $log, array $events): array
    {
        if ($this->allEnemiesDefeated($battle)) {
            return [$log, $events];
        }

        $companion = $bc->tamedCompanion;
        $role = $companion->role ?? 'brute';
        $ability = $this->monsterAi->companionAbility($role);
        // A companion picks its own target off its own grudges instead of blindly mirroring the
        // player's — see ThreatService::topEnemyThreat()/addRetaliationThreat().
        $targetMonsterId = $this->chooseCompanionTarget($battle, $bc, $targetMonsterId);

        // A small MP pool that regenerates on every one of THIS companion's own turns — gates its
        // role-flavored special move (see MonsterAiService::ROLE_COMPANION_ABILITY) instead of it firing
        // every round unconditionally.
        $bc->mp = min($bc->mp_max, $bc->mp + ($ability['mp_regen_per_round'] ?? 15));

        // A mender-sourced companion mends the weakest ally instead of attacking whenever someone's
        // meaningfully hurt AND it has the MP to — same role identity MonsterAiService gives enemy
        // menders, mirrored on the player's own side of the fight. Gated by companionWantsSpecial() so
        // two identical companions don't heal/attack in perfect lockstep every round they can afford it.
        if ($role === 'mender') {
            if ($bc->mp >= $ability['mp_cost'] && $this->companionWantsSpecial()) {
                [$healed, $log, $events] = $this->menderHeal($battle, $stats, $bc, $log, $events);
                if ($healed) {
                    $bc->mp -= $ability['mp_cost'];
                    $bc->save();

                    return [$log, $events];
                }
            }
            $bc->save();

            return $this->companionPlainAttack($battle, $bc, $companion, $targetMonsterId, $log, $events);
        }

        // A buffer-sourced companion empowers the player instead of attacking once it has the MP to —
        // also gated by companionWantsSpecial(), same reasoning as the mender branch above.
        if ($role === 'buffer' && $bc->mp >= $ability['mp_cost'] && $this->companionWantsSpecial()) {
            $bc->mp -= $ability['mp_cost'];
            $bc->save();
            $buff = $ability['buff'];
            $this->statuses->apply($battle, 'player', null, $buff['effect'], $buff['magnitude'], $buff['rounds'], "{$companion->name}'s {$ability['name']}");
            $log[] = "{$companion->name} uses {$ability['name']}, empowering you!";
            $events[] = ['actor' => 'companion', 'actor_id' => $bc->id, 'target' => 'player', 'target_id' => null, 'type' => 'buff'];

            return [$log, $events];
        }

        $bc->save();
        $useSpecial = isset($ability['dmg_mult']) && $bc->mp >= $ability['mp_cost'] && $this->companionWantsSpecial();

        return $this->companionPlainAttack($battle, $bc, $companion, $targetMonsterId, $log, $events, $useSpecial ? $ability : null);
    }

    /** Plain attack, optionally upgraded into the companion's MP-gated special move ($ability, from
     * MonsterAiService::ROLE_COMPANION_ABILITY) — split out from resolveCompanionTurn so the mender's
     * "no MP for Mend this round" fallback reuses the exact same attack path instead of a duplicate. */
    private function companionPlainAttack(Battle $battle, BattleCompanion $bc, TamedCompanion $companion, ?int $targetMonsterId, array $log, array $events, ?array $ability = null): array
    {
        $atkMods = $this->statuses->modifiers($battle, 'companion', $bc->id);
        $dmgMult = $ability['dmg_mult'] ?? 1.0;
        $dmg = max(1, (int) round($companion->effectiveAtk() * $dmgMult * $atkMods['dmg_dealt_mult'] * $this->rand(0.8, 1.2)));

        $target = $targetMonsterId ? $battle->battleMonsters->firstWhere('id', $targetMonsterId) : null;
        if ($target && $target->hp > 0) {
            $dmg = $this->finalizeDamageToTarget($battle, 'monster', $target->id, $dmg);
            $target->hp = max(0, $target->hp - $dmg);
            $target->save();
            $hitName = $target->monster->name;
            $hitTargetId = $target->id;
        } else {
            $dmg = $this->finalizeDamageToTarget($battle, 'monster', 0, $dmg);
            // "Spare tameable enemies" (User::preferences.spare_tameable_enemies) — a companion's own
            // hit never lands the killing blow on a monster the player could still go on to tame, so
            // an unlucky auto-battle round can't rob them of the Attempt to Tame window. Only ever
            // relevant for the primary monster (taming is 1v1-only, see attemptTame()) and only holds
            // it at 1 HP, never heals it back up — the player's own attacks can still finish it.
            $floor = $this->sparesTameableEnemies($battle) ? 1 : 0;
            $battle->monster_hp = max($floor, $battle->monster_hp - $dmg);
            $hitName = $battle->monster->name;
            $hitTargetId = 0;
        }

        if ($ability) {
            $bc->mp -= $ability['mp_cost'];
            $bc->save();
            $log[] = "{$companion->name} uses {$ability['name']} on {$hitName} for {$dmg}!";
            $status = $ability['applies_status'] ?? null;
            if ($status) {
                $target = ($status['target'] ?? 'enemy') === 'self' ? ['companion', $bc->id] : ['monster', $hitTargetId];
                $this->statuses->apply($battle, $target[0], $target[1], $status['effect'], $status['magnitude'], $status['rounds'], "{$companion->name}'s {$ability['name']}");
            }
        } else {
            $log[] = "{$companion->name} hits {$hitName} for {$dmg}.";
        }
        $events[] = ['actor' => 'companion', 'actor_id' => $bc->id, 'target' => 'monster', 'target_id' => $hitTargetId, 'type' => 'damage', 'amount' => $dmg, 'crit' => false];
        $this->threats->addDamageThreat($battle, 'monster', $hitTargetId, 'companion', $bc->id, $dmg);

        // A companion tamed from a species with a themed bonus (see MonsterAiService::SPECIES_STATUS
        // — e.g. Fire Imp) has the same chance to apply it here as that species does as a live enemy.
        [$log, $events] = $this->applySpeciesStatus($battle, $companion->monster?->key, $companion->name, ['type' => 'monster', 'id' => $hitTargetId], $dmg, $log, $events, 'companion', $bc->id);

        return [$log, $events];
    }

    /** Stun/Freeze pre-check wrapper around resolveCompanionTurn — a companion drawing an enemy's debuff
     * (via taunt, or simply being that enemy's top threat) can be incapacitated same as the player/a
     * monster can. */
    private function dispatchCompanionTurn(Battle $battle, array $stats, BattleCompanion $bc, ?int $targetMonsterId, array $log, array $events): array
    {
        if ($this->statuses->isStunned($battle, 'companion', $bc->id)) {
            $this->statuses->consumeOneStun($battle, 'companion', $bc->id);
            $name = $bc->tamedCompanion->name;
            $log[] = "{$name} is stunned and loses its turn!";
            $events[] = ['actor' => 'companion', 'actor_id' => $bc->id, 'target' => 'companion', 'target_id' => $bc->id, 'type' => 'stun'];

            return [$log, $events];
        }

        return $this->resolveCompanionTurn($battle, $stats, $bc, $targetMonsterId, $log, $events);
    }

    /** Stun/Freeze pre-check + cooldown bookkeeping wrapper around resolveMonsterAction — resolves ONE
     * monster's turn (primary, $targetId 0, or a specific add) in the real speed-ordered queue (see
     * resolveRestOfRound). Persists the primary monster's cooldown map back onto $battle; adds don't
     * persist their own (see resolveMonsterAction's docblock — an accepted simplification). */
    private function dispatchMonsterTurn(Battle $battle, array $stats, int $targetId, ?Inventory $armorRow, array $log, array $events): array
    {
        if ($targetId === 0) {
            $monster = $battle->monster;
            $displayName = $monster->name;
            $cooldowns = $battle->monster_cooldowns_json ?? [];
            $hpTarget = null;
        } else {
            $extra = $battle->battleMonsters->firstWhere('id', $targetId);
            if (! $extra || $extra->hp <= 0) {
                return [$log, $events];
            }
            $monster = $extra->monster;
            $displayName = $monster->name;
            $cooldowns = [];
            $hpTarget = $extra;
        }

        if ($this->statuses->isStunned($battle, 'monster', $targetId)) {
            $this->statuses->consumeOneStun($battle, 'monster', $targetId);
            $log[] = "{$displayName} is stunned and loses its turn!";
            $events[] = ['actor' => 'monster', 'actor_id' => $targetId, 'target' => 'monster', 'target_id' => $targetId, 'type' => 'stun'];

            return [$log, $events];
        }

        [$log, $cooldowns, $events] = $this->resolveMonsterAction($battle, $stats, $monster, $targetId, $displayName, $cooldowns, $armorRow, $log, $events, $hpTarget);
        if ($targetId === 0) {
            $battle->monster_cooldowns_json = $cooldowns;
        }

        return [$log, $events];
    }

    /** Walks the REST of the real speed-sorted turn queue (see MonsterAiService::speedOrder()) — every
     * living unit acts once per round, fastest first, ties toward the party side then spawn order — from
     * right after the player's own queue slot (already resolved by the caller before this runs) through
     * to the end of the round, then advances into fresh rounds (rebuilding the queue and ticking
     * statuses each time) until either the battle resolves or the queue would hand the turn back to the
     * player, at which point control returns to them. Mirrors the imported "Solyx Battle Multi" design's
     * own `runUntilPlayer()` loop exactly. A 30-round stalemate guard auto-resolves a fight that's
     * dragged on too long in the enemy's favor, so a Mender/Regen loop can't stall forever.
     *
     * @return array{0: array, 1: array} [updated log, updated events]
     */
    private function resolveRestOfRound(Battle $battle, array $stats, ?Inventory $armorRow, ?int $targetMonsterId, array $log, array $events): array
    {
        $queue = $this->monsterAi->speedOrder($battle);
        $ptr = $this->queuePlayerIndex($queue) + 1;

        $guard = 0;
        while ($guard++ < 200) {
            if ($ptr >= count($queue)) {
                $battle->round = ($battle->round ?? 1) + 1;
                [$log, $events] = $this->tickStatusEffects($battle, $stats, $log, $events);
                if ($this->allEnemiesDefeated($battle) || $battle->character_hp <= 0) {
                    return [$log, $events];
                }
                if ($battle->round > 30) {
                    // Stalemate guard — a healer/regen loop that would otherwise never end resolves in
                    // the enemy's favor instead of dragging on forever.
                    $log[] = 'The fight drags on too long; you are overwhelmed.';
                    $battle->character_hp = 0;

                    return [$log, $events];
                }
                $queue = $this->monsterAi->speedOrder($battle);
                $ptr = 0;
                continue;
            }

            $entry = $queue[$ptr];
            if ($entry['side'] === 'player' && $entry['id'] === null) {
                return [$log, $events];
            }

            if ($entry['side'] === 'player') {
                $bc = $battle->battleCompanions->firstWhere('id', $entry['id']);
                if ($bc && $bc->hp > 0) {
                    [$log, $events] = $this->dispatchCompanionTurn($battle, $stats, $bc, $targetMonsterId, $log, $events);
                }
            } else {
                [$log, $events] = $this->dispatchMonsterTurn($battle, $stats, $entry['id'] ?? 0, $armorRow, $log, $events);
            }

            if ($this->allEnemiesDefeated($battle) || $battle->character_hp <= 0) {
                return [$log, $events];
            }
            $ptr++;
        }

        return [$log, $events];
    }

    private function queuePlayerIndex(array $queue): int
    {
        foreach ($queue as $i => $entry) {
            if ($entry['side'] === 'player' && $entry['id'] === null) {
                return $i;
            }
        }

        return -1;
    }

    /** Rolls MonsterAiService::speciesStatus()'s themed bonus chance for $monsterKey and, if it hits,
     * applies it to $defender on top of whatever else this attack already did — shared by wild monster
     * attacks and tamed companion attacks so the same species behaves identically either side of the
     * fight. No-op (returns $log/$events unchanged) if there's no species entry or the roll misses. */
    private function applySpeciesStatus(Battle $battle, ?string $monsterKey, string $displayName, array $defender, int $dealtDamage, array $log, array $events, string $actorType, ?int $actorId): array
    {
        $species = $this->monsterAi->speciesStatus($monsterKey);
        if (! $species || ! $this->rollPercent($species['chance'])) {
            return [$log, $events];
        }

        $magnitude = isset($species['magnitude_pct_of_damage'])
            ? $dealtDamage * $species['magnitude_pct_of_damage'] / 100
            : (float) ($species['magnitude'] ?? 1);
        $targetLabel = $defender['type'] === 'companion' ? ($defender['row']?->tamedCompanion->name) : null;

        $this->statuses->apply($battle, $defender['type'], $defender['id'], $species['effect'], $magnitude, (int) $species['rounds'], $displayName);
        $log[] = $this->statusLogLine($displayName, $species, $targetLabel, $magnitude);
        $events[] = ['actor' => $actorType, 'actor_id' => $actorId, 'target' => $defender['type'], 'target_id' => $defender['id'], 'type' => 'status', 'status_key' => $species['effect']];

        return [$log, $events];
    }

    /** Heals whichever ally (the player, or another living companion) is most hurt, as long as someone's
     * below 90% — a mender companion never wastes its turn topping off an ally that's basically fine.
     * Returns [didHeal, updatedLog, updatedEvents]. */
    private function menderHeal(Battle $battle, array $stats, BattleCompanion $healerRow, array $log, array $events): array
    {
        $healer = $healerRow->tamedCompanion;
        $candidates = [['pct' => $battle->character_hp / max(1, $stats['eff_hp_max']), 'name' => 'you', 'player' => true, 'row' => null]];
        foreach ($battle->battleCompanions as $other) {
            if ($other->hp <= 0) {
                continue;
            }
            $candidates[] = ['pct' => $other->hp / max(1, $other->hp_max), 'name' => $other->tamedCompanion->name, 'player' => false, 'row' => $other];
        }

        usort($candidates, fn ($a, $b) => $a['pct'] <=> $b['pct']);
        $weakest = $candidates[0];
        if ($weakest['pct'] >= 0.9) {
            return [false, $log, $events];
        }

        $healAmt = max(1, (int) round($healer->effectiveHpMax() * 0.15));
        if ($weakest['player']) {
            $battle->character_hp = min($stats['eff_hp_max'], $battle->character_hp + $healAmt);
            $targetType = 'player';
            $targetId = null;
        } else {
            $row = $weakest['row'];
            $row->hp = min($row->hp_max, $row->hp + $healAmt);
            $row->save();
            $targetType = 'companion';
            $targetId = $row->id;
        }

        $log[] = "{$healer->name} mends {$weakest['name']} for {$healAmt}.";
        $events[] = ['actor' => 'companion', 'actor_id' => $healerRow->id, 'target' => $targetType, 'target_id' => $targetId, 'type' => 'heal', 'amount' => $healAmt];
        // Healing generates threat on EVERY living enemy at once (see ThreatService) — a heal mid-pack
        // can flip several targets onto the healer in one go, mirroring the imported design's threat model.
        $this->threats->addHealThreatToAllEnemies($battle, 'companion', $healerRow->id, $healAmt);

        return [true, $log, $events];
    }

    /** One monster's (primary or add) full role-based turn: pick an ability via MonsterAiService, resolve
     * it (regen heals itself, 'buff' rallies its side instead of attacking, otherwise it picks a single
     * target — the player or one living tamed companion, see chooseEnemyTarget() — and hits it, applying
     * any `applies_status` entries to that same target), and log it. $hpTarget is the BattleMonster row
     * to heal against for an add's regen (the primary monster instead uses $battle->monster_hp/_hp_max
     * directly).
     *
     * @return array{0: array, 1: array, 2: array} [updated log, updated cooldowns, updated events]
     */
    private function resolveMonsterAction(Battle $battle, array $stats, Monster $monster, int $targetId, string $displayName, array $cooldowns, ?Inventory $armorRow, array $log, array $events, ?BattleMonster $hpTarget = null): array
    {
        [$ability, $cooldowns] = $this->monsterAi->choose($monster, $cooldowns);

        if ($ability['type'] === 'regen') {
            if ($hpTarget) {
                $healed = min($hpTarget->hp_max - $hpTarget->hp, (int) round($hpTarget->hp_max * ($ability['heal_pct'] ?? 0) / 100));
                $hpTarget->hp += $healed;
                $hpTarget->save();
            } else {
                $maxHp = $battle->monster_hp_max ?? $monster->hp;
                $healed = min($maxHp - $battle->monster_hp, (int) round($maxHp * ($ability['heal_pct'] ?? 0) / 100));
                $battle->monster_hp += $healed;
            }
            $log[] = "{$displayName} uses {$ability['name']} and regenerates {$healed} HP!";
            $events[] = ['actor' => 'monster', 'actor_id' => $targetId, 'target' => 'monster', 'target_id' => $targetId, 'type' => 'heal', 'amount' => $healed];

            return [$log, $cooldowns, $events];
        }

        if ($ability['type'] === 'buff') {
            [$log, $events] = $this->applyMonsterSelfBuff($battle, $ability, $displayName, $log, $events);

            return [$log, $cooldowns, $events];
        }

        $hits = max(1, $ability['hits'] ?? 1);
        $gradeAtkMult = $this->grades->atkMult($battle->grade);
        $roleAtkMult = $this->monsterAi->buildFor($monster->role)['atk_mult'];
        $atkMods = $this->statuses->modifiers($battle, 'monster', $targetId);

        // Which single target this attack actually lands on — the player, or one living tamed companion
        // — chosen via taunt override, a skirmisher's "hit the weakest" priority, or (see
        // ThreatService) whoever THIS specific enemy has logged the most threat against. Replaces the
        // old flat %-per-pet damage-share split: exactly one defender now eats the whole hit, same as
        // the player picking one enemy to attack.
        $defender = $this->chooseEnemyTarget($battle, $stats, $monster->role, $targetId);

        // Dodge is rolled per attack (not once for the whole round) so a multi-enemy pack fight resolves
        // each hit independently, same as the player's own attacks always have — only the player has a
        // dodge stat, so a companion drawing the attack instead can't dodge it.
        if ($defender['type'] === 'player') {
            $evasionDelta = $this->statuses->modifiers($battle, 'player', null)['evasion_delta'];
            if ($this->rollPercent(max(0, ($stats['dodge_chance'] ?? 0) + $evasionDelta))) {
                $verb = ($ability['key'] ?? null) === 'basic_attack' ? 'attack' : $ability['name'];
                $log[] = "You dodge {$displayName}'s {$verb}!";
                $events[] = ['actor' => 'monster', 'actor_id' => $targetId, 'target' => 'player', 'target_id' => null, 'type' => 'dodge'];

                return [$log, $cooldowns, $events];
            }
        }

        $defMods = $this->statuses->modifiers($battle, $defender['type'], $defender['id']);
        $defenderDef = $defender['type'] === 'companion' ? $defender['row']->tamedCompanion->effectiveDef() : $stats['eff_def'];

        $enemyDmg = 0;
        for ($i = 0; $i < $hits; $i++) {
            $enemyDmg += max(2, (int) round($monster->atk * $gradeAtkMult * $roleAtkMult * ($ability['dmg_mult'] ?? 1.0) * $atkMods['dmg_dealt_mult'] * $this->rand(0.7, 1.3) - $defenderDef * $defMods['def_mult'] * 0.25));
        }
        // dmg_taken_mult (Vulnerable/Break) is applied once, inside finalizeDamageToTarget() below —
        // it must not also be applied here, or a debuff like Vulnerable (1.20x) would compound to 1.44x.
        $finalDmg = $this->finalizeDamageToTarget($battle, $defender['type'], $defender['id'], $enemyDmg);

        if ($defender['type'] === 'companion') {
            $bc = $defender['row'];
            $companion = $bc->tamedCompanion;
            $bc->hp = max(0, $bc->hp - $finalDmg);
            $bc->save();
            // This companion's own grudge against whichever monster just hit it — see
            // ThreatService::topEnemyThreat(), which chooseCompanionTarget() reads back below.
            $this->threats->addRetaliationThreat($battle, $bc->id, 'monster', $targetId, $finalDmg);
            $note = '';
            if ($bc->hp <= 0) {
                $this->downCompanion($bc);
                $note = ' (goes down)';
            } else {
                $companion->current_hp = $bc->hp;
                $companion->save();
            }
            $targetLabel = $companion->name;
        } else {
            $battle->character_hp = max(0, $battle->character_hp - $finalDmg);
            $note = '';
            $targetLabel = 'you';
        }

        $events[] = ['actor' => 'monster', 'actor_id' => $targetId, 'target' => $defender['type'], 'target_id' => $defender['id'], 'type' => 'damage', 'amount' => $finalDmg, 'crit' => false];
        if (($ability['key'] ?? null) === 'basic_attack') {
            $log[] = "{$displayName} hits {$targetLabel} for {$finalDmg}.{$note}";
        } else {
            $hitNote = $hits > 1 ? " ({$hits} hits)" : '';
            $log[] = "{$displayName} uses {$ability['name']} for {$finalDmg}{$hitNote} on {$targetLabel}.{$note}";
        }
        // Only the player's own armor wears down when the player is the one actually hit — a companion
        // absorbing the attack instead shouldn't decay gear it never touched.
        if ($defender['type'] === 'player') {
            $this->decayGear($armorRow);
        }

        // Debuffs land on the same target the damage just did — a companion drawing the attack (via
        // taunt, or simply the aggro roll) absorbs its status too, not just its damage, so pets are a
        // real target for buffs/debuffs and not only the player.
        foreach ($ability['applies_status'] ?? [] as $statusDef) {
            // Same dynamic-magnitude resolution as player skills (see applySkillStatuses) — a monster/
            // companion ability naming `magnitude_pct_of_damage` (e.g. Fire Imp's Fireball for Burn)
            // scales off the hit it just actually landed, not a fixed authored number.
            $magnitude = isset($statusDef['magnitude_pct_of_damage'])
                ? $finalDmg * $statusDef['magnitude_pct_of_damage'] / 100
                : (float) $statusDef['magnitude'];
            $this->statuses->apply($battle, $defender['type'], $defender['id'], $statusDef['effect'], $magnitude, (int) $statusDef['rounds'], $ability['name'] ?? $displayName);
            $log[] = $this->statusLogLine($displayName, $statusDef, $defender['type'] === 'companion' ? $targetLabel : null, $magnitude);
            $events[] = ['actor' => 'monster', 'actor_id' => $targetId, 'target' => $defender['type'], 'target_id' => $defender['id'], 'type' => 'status', 'status_key' => $statusDef['effect']];
        }

        // A themed bonus chance on top of whatever the ability/role already did — see
        // MonsterAiService::SPECIES_STATUS. Independent roll, so e.g. a Fire Imp can both shred armor
        // via its elite_brute role AND occasionally Burn from being, specifically, a Fire Imp.
        [$log, $events] = $this->applySpeciesStatus($battle, $monster->key, $displayName, $defender, $finalDmg, $log, $events, 'monster', $targetId);

        return [$log, $cooldowns, $events];
    }

    /** Which single target (the player, or one living tamed companion) an enemy's attack actually lands
     * on this turn — replaces the old flat "redirect a %-per-pet share to every pet" split. A `taunt`
     * status (see e.g. Bonded Guard) always wins outright; a `skirmisher`-role attacker always goes for
     * whoever's lowest on HP%; everyone else goes to whoever THIS specific enemy ($enemyTargetId) has
     * logged the most accumulated threat against (see ThreatService) — defaulting to the player on a
     * fresh encounter, or if its top pick is a companion that's since gone down.
     *
     * @return array{type: string, id: ?int, row: ?BattleCompanion}
     */
    private function chooseEnemyTarget(Battle $battle, array $stats, ?string $attackerRole, int $enemyTargetId): array
    {
        $companions = $battle->battleCompanions->where('hp', '>', 0)->values();
        if ($companions->isEmpty()) {
            return ['type' => 'player', 'id' => null, 'row' => null];
        }

        $taunting = $companions->first(fn (BattleCompanion $bc) => $this->statuses->isTaunting($battle, 'companion', $bc->id));
        if ($taunting) {
            return ['type' => 'companion', 'id' => $taunting->id, 'row' => $taunting];
        }

        if ($attackerRole === 'skirmisher') {
            $candidates = [['type' => 'player', 'id' => null, 'row' => null, 'pct' => $battle->character_hp / max(1, $stats['eff_hp_max'])]];
            foreach ($companions as $bc) {
                $candidates[] = ['type' => 'companion', 'id' => $bc->id, 'row' => $bc, 'pct' => $bc->hp / max(1, $bc->hp_max)];
            }
            usort($candidates, fn ($a, $b) => $a['pct'] <=> $b['pct']);

            return $candidates[0];
        }

        $top = $this->threats->topThreatUnit($battle, 'monster', $enemyTargetId);
        if ($top['type'] === 'companion') {
            $bc = $companions->firstWhere('id', $top['id']);
            if ($bc) {
                return ['type' => 'companion', 'id' => $bc->id, 'row' => $bc];
            }
        }

        return ['type' => 'player', 'id' => null, 'row' => null];
    }

    /** Which enemy (the primary monster = id 0, or one living "add") a companion's attack actually
     * lands on this turn — its own aggro pick, built off ThreatService::topEnemyThreat() (the reverse
     * of chooseEnemyTarget()'s threat-table branch): whichever living enemy has actually been hurting
     * THIS companion wins, same "attack whoever's been hitting you" logic every role gets, not just
     * skirmishers. With no grudge recorded yet (a fresh encounter, or nothing's hit this companion this
     * fight), it rolls its own random living enemy instead of defaulting to whatever the player is
     * currently targeting — so two companions, even two of the exact same tamed species, act on their
     * own instead of mirroring the player (and each other) turn one. */
    private function chooseCompanionTarget(Battle $battle, BattleCompanion $bc, ?int $targetMonsterId): ?int
    {
        $grudge = $this->threats->topEnemyThreat($battle, $bc->id);
        if ($grudge !== null) {
            return $grudge;
        }

        $candidates = [];
        if ($battle->monster_hp > 0) {
            $candidates[] = 0;
        }
        foreach ($battle->battleMonsters->where('hp', '>', 0) as $add) {
            $candidates[] = $add->id;
        }

        return $candidates ? $candidates[mt_rand(0, count($candidates) - 1)] : $targetMonsterId;
    }

    /** Whether the player has "spare tameable enemies" on (User::preferences.spare_tameable_enemies)
     * AND the primary monster is actually still a live tame prospect right now — every attemptTame()
     * guard except the HP% one (see there), since the whole point is to never let a companion's hit be
     * what takes it below that threshold in the first place. Only meaningful in a 1v1 (taming is
     * 1v1-only); always false with any add still alive. */
    private function sparesTameableEnemies(Battle $battle): bool
    {
        $character = $battle->character;
        if (! $character || ! ($character->user?->preferences['spare_tameable_enemies'] ?? false)) {
            return false;
        }
        if ($battle->battleMonsters->isNotEmpty()) {
            return false;
        }

        $monster = $battle->monster;
        if (! $monster || $monster->is_boss || $battle->tame_eligible === false) {
            return false;
        }
        if ($character->level < $monster->tameUnlockLevel()) {
            return false;
        }

        $rosterCount = TamedCompanion::where('character_id', $character->id)->whereNull('archived_at')->count();

        return $rosterCount < $character->user->tameRosterCap();
    }

    /** A `buffer`-role monster's turn doesn't attack at all — it empowers itself and every other living
     * monster on its side (primary + adds) with the ability's buff for buff_rounds, mirroring 'mender'
     * healing instead of attacking. Gives packs a real support threat that costs something to ignore. */
    private function applyMonsterSelfBuff(Battle $battle, array $ability, string $displayName, array $log, array $events): array
    {
        $targets = [];
        if ($battle->monster_hp > 0) {
            $targets[] = ['type' => 'monster', 'id' => 0];
        }
        foreach ($battle->battleMonsters as $extra) {
            if ($extra->hp > 0) {
                $targets[] = ['type' => 'monster', 'id' => $extra->id];
            }
        }

        foreach ($targets as $t) {
            $this->statuses->apply($battle, $t['type'], $t['id'], $ability['buff_effect'], (float) $ability['buff_magnitude'], (int) $ability['buff_rounds'], $ability['name']);
            $events[] = ['actor' => 'monster', 'actor_id' => $t['id'], 'target' => $t['type'], 'target_id' => $t['id'], 'type' => 'status', 'status_key' => $ability['buff_effect']];
        }
        $log[] = "{$displayName} uses {$ability['name']}, rallying its allies!";

        return [$log, $events];
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
        abort_if($this->statuses->isRooted($battle, 'player', null), 422, "You're rooted and can't flee this battle!");

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

        // Pack-clear bonus — clearing every enemy in a multi-monster "pack" encounter (see
        // CombatService::rollPackMonsters()) pays extra on top of the plain per-monster sum, scaling up
        // to the full pack_clear_bonus_mult as the pack gets bigger (a 4-monster pack, i.e. 3 extras,
        // reaches the full bonus; smaller packs get a proportional slice). Always 1.0 for a normal 1v1
        // fight or a dungeon boss's fixed adds count toward it too, same as any other pack.
        $extraCount = $battle->battleMonsters->count();
        $packBonusMult = 1.0;
        if ($extraCount > 0) {
            $fullPackMult = GameConfig::number('pack_clear_bonus_mult', 1.6);
            $packBonusMult = 1 + ($fullPackMult - 1) * min(1, $extraCount / 3);
        }

        $goldGain = (int) round($allMonsters->sum('gold') * $goldMult * $gradeRewardMult * $zoneMult * $packBonusMult * (1 + $luckBonus + $vipGoldXpBonus + $guildXpBonus + $guildGoldFindBonus));
        $xpGain = (int) round($allMonsters->sum('xp') * $xpMult * $gradeRewardMult * $zoneMult * $packBonusMult * (1 + ($luckBonus * $xpFactor) + $petXpBonus + $vipGoldXpBonus + $guildXpBonus + $guildXpUpgradeBonus));
        $gemGain = (int) round($allMonsters->sum('gems') * $gemMult * $gradeRewardMult * $zoneMult * $packBonusMult * (1 + ($luckBonus * $gemFactor)));

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
                'pack_pct' => (int) round(($packBonusMult - 1) * 100),
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
                'pack_pct' => (int) round(($packBonusMult - 1) * 100),
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

        [$leveledUp, $milestoneGrants] = $this->grantXp($character, $xpGain);
        $petResults = $this->grantPetXp($character, (int) round($xpGain * 0.2));
        // Battle Pass xp deliberately does NOT come from combat anymore — it's quest-based only (see
        // QuestController::claim), so grinding/auto-battling a high-xp zone can no longer max the whole
        // season pass by itself the way the old uncapped 0.3x-of-battle-xp hook allowed.
        $this->grantPartyShare($character, $goldGain, $xpGain, $gemGain);

        foreach ($petResults as $petResult) {
            $log[] = "{$petResult['name']} gained companion XP.".($petResult['leveled_up'] ? " Now level {$petResult['level']}!" : '');
        }
        foreach ($milestoneGrants as $grant) {
            $log[] = "🎁 Level-up gift: {$grant}!";
        }
        $petFoodDropped = $this->maybeDropPetFood($character, $monster, $log);
        $crateDropped = $this->maybeDropLootCrate($character, $monster, $log);
        $monsterLootDropped = $this->maybeDropMonsterLoot($character, $monster, $log);
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
                'crate_dropped' => $crateDropped,
                'monster_loot_dropped' => $monsterLootDropped,
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

        $battle->update(['status' => 'lost', 'log_json' => $log]);

        $freshCharacter = $character->fresh(['attributes_', 'inventory.item', 'skills.skill']);

        return [
            'battle' => $battle->fresh(['monster', 'battleMonsters.monster', 'battleCompanions.tamedCompanion']),
            'result' => [
                'outcome' => 'lost',
                'gold_lost' => $penalty['gold_lost'],
                'xp_lost' => $penalty['xp_lost'],
                'character' => $freshCharacter,
                'stats' => $freshCharacter->effectiveStats(),
            ],
        ];
    }

    /**
     * Death penalty: lose a slice of gold and xp. XP loss is floored at the current level's own XP
     * threshold — a bad loss can wipe out this level's progress but can never drop a character below
     * the level they're already at (no "delevel").
     */
    private function applyDeathPenalty(Character $character): array
    {
        $goldLossPct = GameConfig::number('death_gold_loss_pct', 8);
        $baseLossPct = GameConfig::number('death_xp_loss_pct', 5);

        $goldLost = (int) min($character->gold, round($character->gold * $goldLossPct / 100));

        $previousLevelXp = Character::xpForLevel(max(1, $character->level - 1));

        // How much XP the player has earned within this level
        $xpProgressInLevel = max(0, $character->xp - $previousLevelXp);

        // Loss: 5% of YOUR PROGRESS in this level + flat amount
        $percentageLoss = (int) round($xpProgressInLevel * $baseLossPct / 100);
        $flatLoss = (int) round(100 * (($character->level / 100) + 1));
        $xpLost = $percentageLoss + $flatLoss;

        // Floored at this level's own XP threshold, never below it — a loss can only erase progress
        // made within the current level, never push the character back into the previous one.
        $xp = max($previousLevelXp, $character->xp - $xpLost);

        $character->gold = max(0, $character->gold - $goldLost);
        $character->xp = $xp;
        $character->save();

        return ['gold_lost' => $goldLost, 'xp_lost' => $xpLost];
    }

    /** Applies xp, handling multi-level-ups (capped at Character::MAX_LEVEL).
     *
     * @return array{0: int, 1: string[]} [levels gained, one-time milestone rewards granted this call —
     *                                      see grantLevelMilestones()]
     */
    public function grantXp(Character $character, int $xpGain): array
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

        $grants = $levelsGained > 0 ? $this->grantLevelMilestones($character, $oldLevel, $level) : [];

        return [$levelsGained, $grants];
    }

    /** Class-flavored level-8 starter weapon (see ItemSeeder), reused here as the level-3 one-time gift —
     * Inventory unlocks at level 3 too (see navigation.js), and without this it's an empty Equipment panel
     * until level 8's real weapon tier becomes buyable/craftable. min_level on the item itself is purely
     * informational (InventoryController::equip() doesn't gate on it), so handing it out 5 levels early is
     * safe to equip immediately. */
    private const STARTER_WEAPON_BY_CLASS = [
        'warrior' => 'iron_sword', 'mage' => 'oak_staff', 'rogue' => 'iron_dagger', 'ranger' => 'wooden_bow',
    ];

    /** One-time rewards the moment a character first crosses a given level — checked as oldLevel < X <=
     * newLevel (not levelsGained === N) so a big XP dump that jumps several levels at once still grants
     * every milestone it passed through, not just the final level landed on. Returns a human-readable
     * description per grant for the caller to log if it has somewhere to put that (resolveWin's battle
     * log does; the silent party-share XP grant in grantPartyShare() just discards it). */
    private function grantLevelMilestones(Character $character, int $oldLevel, int $newLevel): array
    {
        $grants = [];

        if ($oldLevel < 3 && $newLevel >= 3) {
            $weaponKey = self::STARTER_WEAPON_BY_CLASS[$character->base_class] ?? null;
            $item = $weaponKey ? Item::where('key', $weaponKey)->first() : null;
            if ($item) {
                $max = $this->durability->maxDurability($item->rarity);
                Inventory::create([
                    'character_id' => $character->id, 'item_id' => $item->id, 'qty' => 1, 'equipped' => false,
                    'durability' => $max, 'durability_max' => $max,
                ]);
                $grants[] = "{$item->name}";
            }
        }

        if ($oldLevel < 4 && $newLevel >= 4) {
            $granted = $this->grantStackableReward($character, 'common_repair_pack', 5);
            if ($granted) $grants[] = "5x {$granted}";
        }

        if ($oldLevel < 5 && $newLevel >= 5) {
            $granted = $this->grantStackableReward($character, 'health_potion', 5);
            if ($granted) $grants[] = "5x {$granted}";
        }

        return $grants;
    }

    /** Mirrors maybeDropPetFood()/maybeDropLootCrate()'s firstOrNew-then-increment pattern for a stackable
     * (non-gear) item grant. Returns the item's name (for the caller's log line) or null if the key isn't seeded. */
    private function grantStackableReward(Character $character, string $itemKey, int $qty): ?string
    {
        $item = Item::where('key', $itemKey)->first();
        if (! $item) {
            return null;
        }

        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + $qty;
        $inventory->save();

        return $item->name;
    }

    private function rand(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    private function rollPercent(float $percentChance): bool
    {
        return (mt_rand() / mt_getrandmax() * 100) < $percentChance;
    }

    /** Whether a companion, once it can afford its role ability this round, actually uses it — a fresh
     * independent roll every turn, so two companions with identical MP regen (even two of the exact
     * same tamed species) don't heal/buff/nuke in perfect lockstep every round they're both able to. */
    private function companionWantsSpecial(): bool
    {
        return $this->rollPercent(GameConfig::number('companion_special_use_pct', 65));
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

    /** Rolls the monster's own zone/boss-specific material drops (see MonsterSeeder's loot_table_json —
     * each entry is independent, so a monster with multiple entries can drop more than one on the same
     * win). Distinct from the pet-food/lootbox rolls above: this is the ONLY mechanism that hands out a
     * specific, named item tied to a specific monster rather than a random pick or a generic chance.
     * Returns the names of everything actually dropped, for the result payload's reward chips. */
    private function maybeDropMonsterLoot(Character $character, Monster $monster, array &$log): array
    {
        $dropped = [];

        foreach ($monster->loot_table_json ?? [] as $entry) {
            if (! $this->rollPercent($entry['chance_pct'] ?? 0)) {
                continue;
            }

            $item = Item::find($entry['item_id'] ?? null);
            if (! $item) {
                continue;
            }

            $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
            $inventory->qty = ($inventory->qty ?? 0) + 1;
            $inventory->save();

            $log[] = "{$item->name} dropped!";
            $dropped[] = $item->name;
        }

        return $dropped;
    }

    /** Chance to grant one lootbox on a battle win — the box itself is just an inert inventory item until
     * the player opens it via CrateController/CrateService, which rolls and times its rewards. Its
     * RARITY corresponds to the tier of enemy that dropped it: trash mobs roll a small chance at Common,
     * elites roll a better chance at Rare, and bosses (near-)guarantee Epic with a shrinking chance to
     * cascade all the way up to Mythic — each further upgrade is deliberately rarer than the last, so a
     * Mythic lootbox off a lowly trash mob is technically possible, just vanishingly so. All percentages
     * are GM-tunable. Mirrors maybeDropPetFood()'s log/return shape. */
    private function maybeDropLootCrate(Character $character, Monster $monster, array &$log): ?string
    {
        $rarity = $this->rollLootboxRarity($monster);
        if (! $rarity) {
            return null;
        }

        $item = Item::where('key', "{$rarity}_lootbox")->first();
        if (! $item) {
            return null;
        }

        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + 1;
        $inventory->save();

        $log[] = "{$item->name} dropped!";

        return $item->name;
    }

    private const LOOTBOX_RARITY_LADDER = ['common', 'rare', 'epic', 'legendary', 'mythic'];

    private function rollLootboxRarity(Monster $monster): ?string
    {
        if ($monster->is_boss) {
            if (! $this->rollPercent(GameConfig::number('loot_crate_drop_chance_boss_pct', 100))) {
                return null;
            }
            $floor = 'epic';
        } elseif ($monster->is_elite) {
            if (! $this->rollPercent(GameConfig::number('loot_crate_drop_chance_elite_pct', 20))) {
                return null;
            }
            $floor = 'rare';
        } else {
            if (! $this->rollPercent(GameConfig::number('loot_crate_drop_chance_trash_pct', 8))) {
                return null;
            }
            $floor = 'common';
        }

        // Cascading upgrade roll — each step up the ladder is only attempted after the previous one
        // lands, and gets rarer itself every step (25% of the last chance), so reaching Mythic from a
        // Common floor requires clearing 4 independently-shrinking rolls in a row.
        $i = array_search($floor, self::LOOTBOX_RARITY_LADDER, true);
        $upgradeChance = GameConfig::number('loot_crate_boss_huge_chance_pct', 25);
        while ($i < count(self::LOOTBOX_RARITY_LADDER) - 1 && $this->rollPercent($upgradeChance)) {
            $i++;
            $upgradeChance *= 0.25;
        }

        return self::LOOTBOX_RARITY_LADDER[$i];
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
        abort_if($hpPct > 0.10, 422, 'This enemy is still too strong to tame, wear it down first.');

        $unlockLevel = $monster->tameUnlockLevel();
        abort_if($character->level < $unlockLevel, 422, "You need to be level {$unlockLevel} to tame monsters from this zone.");

        $user = $character->user;
        $rosterCount = TamedCompanion::where('character_id', $character->id)->whereNull('archived_at')->count();
        abort_if($rosterCount >= $user->tameRosterCap(), 422, 'Your companion roster is full; release one from the Companions page first.');

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
                'role' => $monster->role,
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
        // A failed tame attempt still gets a counter-hit from the primary monster, exactly like a
        // whiffed attack — taming is 1v1-only (no adds) and never routes through the real turn queue
        // (no dedicated animated-turn UI here), so this is just the one monster's turn, not a full round
        // walk. Events aren't surfaced from this endpoint, so the structured breakdown is discarded.
        [$log] = $this->dispatchMonsterTurn($battle, $stats, 0, $armorRow, $log, []);
        [$log] = $this->tickStatusEffects($battle, $stats, $log, []);

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
