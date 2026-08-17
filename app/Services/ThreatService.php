<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\BattleCompanion;

/**
 * Per-enemy threat table (see the imported "Solyx Battle Multi" design's THREAT MODEL panel) — replaces
 * the old flat %-per-pet damage-share/aggro-weight-roll targeting. Threat accumulates per (enemy,
 * attacker) pair for the whole battle and never decays on its own; by default, an enemy's attack goes to
 * whoever it has logged the MOST threat against (role/taunt overrides in CombatService::chooseEnemyTarget
 * still take priority on top of this).
 *
 * Storage: Battle::threat_json is [enemyKey => [attackerKey => amount]]. enemyKey is 'monster:0' (the
 * primary monster slot) or 'monster:<battle_monster_id>' (an add); attackerKey is 'player' or
 * 'companion:<battle_companion_id>'.
 */
class ThreatService
{
    public function enemyKey(string $targetType, ?int $targetId): string
    {
        return "{$targetType}:".($targetId ?? 0);
    }

    public function attackerKey(string $actorType, ?int $actorId): string
    {
        return $actorType === 'player' ? 'player' : "{$actorType}:{$actorId}";
    }

    /** Damage generates threat only on the enemy actually hit — a companion that never touches a
     * particular enemy will never pull it via this path. A player with `threat_reduction_pct` (see
     * Character::threatReductionPct()) generates less of it, making them relatively less likely to be
     * the one every enemy in a pack piles onto — companions are unaffected, this is a player-only perk. */
    public function addDamageThreat(Battle $battle, string $targetType, ?int $targetId, string $actorType, ?int $actorId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $this->add($battle, $this->enemyKey($targetType, $targetId), $this->attackerKey($actorType, $actorId), $this->playerScaled($battle, $actorType, $amount));
    }

    /** Healing generates 60% of the healed amount as threat on EVERY living enemy (primary + adds) — a
     * heal mid-pack can flip several targets onto the healer at once, mirroring the design's
     * `threat[*][u] += heal * 0.6` rule. Also scaled down by the player's own threat_reduction_pct when
     * they're the one healing (a companion mending someone isn't affected by the player's perk). */
    public function addHealThreatToAllEnemies(Battle $battle, string $actorType, ?int $actorId, float $healAmount): void
    {
        if ($healAmount <= 0) {
            return;
        }
        $threatAmount = $this->playerScaled($battle, $actorType, $healAmount * 0.6);
        $attacker = $this->attackerKey($actorType, $actorId);

        if ($battle->monster_hp > 0) {
            $this->add($battle, $this->enemyKey('monster', 0), $attacker, $threatAmount);
        }
        foreach ($battle->battleMonsters as $extra) {
            if ($extra->hp > 0) {
                $this->add($battle, $this->enemyKey('monster', $extra->id), $attacker, $threatAmount);
            }
        }
    }

    /** Applies the player's threat_reduction_pct to $amount when $actorType is 'player' — a no-op
     * (returns $amount unchanged) for companion actors or a player with no such passive. */
    private function playerScaled(Battle $battle, string $actorType, float $amount): float
    {
        if ($actorType !== 'player') {
            return $amount;
        }
        $reduction = $battle->character?->threatReductionPct() ?? 0;

        return $reduction > 0 ? $amount * max(0, 1 - $reduction / 100) : $amount;
    }

    private function add(Battle $battle, string $enemyKey, string $attackerKey, float $amount): void
    {
        $threat = $battle->threat_json ?? [];
        $threat[$enemyKey][$attackerKey] = ($threat[$enemyKey][$attackerKey] ?? 0) + $amount;
        $battle->threat_json = $threat;
    }

    /** The reverse of addDamageThreat() — a companion's own grudge list (Battle::companion_threat_json,
     * [companionKey => [enemyKey => amount]]) instead of an enemy's. Lets a tamed companion pick its own
     * attack target off who's actually been hurting IT, instead of always mirroring whatever the player
     * is currently targeting. Player damage received isn't tracked here — nothing reads it back yet, and
     * the player already picks their own target every turn. */
    public function addRetaliationThreat(Battle $battle, int $companionId, string $enemyType, ?int $enemyId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $companionKey = "companion:{$companionId}";
        $enemyKey = $this->enemyKey($enemyType, $enemyId);
        $threat = $battle->companion_threat_json ?? [];
        $threat[$companionKey][$enemyKey] = ($threat[$companionKey][$enemyKey] ?? 0) + $amount;
        $battle->companion_threat_json = $threat;
    }

    /** Which living enemy (primary = id 0, or a still-alive "add") this specific companion has taken the
     * most cumulative damage from — its own aggro pick, mirroring topThreatUnit() in reverse. Returns
     * null with no grudge recorded yet (fresh encounter) or if its top pick isn't alive anymore; the
     * caller falls back to the player's current target either way, same single-level fallback
     * chooseEnemyTarget() already uses for its own threat-table branch. */
    public function topEnemyThreat(Battle $battle, int $companionId): ?int
    {
        $row = ($battle->companion_threat_json ?? [])["companion:{$companionId}"] ?? [];
        if (empty($row)) {
            return null;
        }

        arsort($row);
        [, $topId] = explode(':', array_key_first($row), 2);
        $topId = (int) $topId;

        $alive = $topId === 0 ? $battle->monster_hp > 0 : ($battle->battleMonsters->firstWhere('id', $topId)?->hp ?? 0) > 0;

        return $alive ? $topId : null;
    }

    /** Whoever (the player, or a living companion) this specific enemy has logged the MOST threat
     * against — defaults to the player when there's no threat recorded yet (a fresh encounter) or
     * nobody's ever hit it. Doesn't check whether that unit is still alive — the caller
     * (CombatService::chooseEnemyTarget) is responsible for only offering living candidates and falling
     * back if the top pick isn't one. */
    public function topThreatUnit(Battle $battle, string $targetType, ?int $targetId): array
    {
        $row = ($battle->threat_json ?? [])[$this->enemyKey($targetType, $targetId)] ?? [];
        if (empty($row)) {
            return ['type' => 'player', 'id' => null];
        }

        arsort($row);
        $topKey = array_key_first($row);
        if ($topKey === 'player') {
            return ['type' => 'player', 'id' => null];
        }
        [$type, $id] = explode(':', $topKey, 2);

        return ['type' => $type, 'id' => (int) $id];
    }

    /** Display-ready version of who this enemy is actually about to hit — mirrors
     * CombatService::chooseEnemyTarget()'s exact priority (taunt > skirmisher-role lowest-HP% > threat
     * table > player fallback), so the aggro tag shown on an enemy card can never say something the
     * real turn resolution wouldn't do. Skirmisher is reported as "Lowest HP%" rather than naming a
     * specific unit, since that pick is re-rolled fresh off live HP every round rather than read from a
     * stored table — naming a name here would be a guess, not a fact. */
    public function topThreatLabel(Battle $battle, string $targetType, ?int $targetId, ?string $role = null): array
    {
        $companions = $battle->battleCompanions->where('hp', '>', 0)->values();

        if ($companions->isNotEmpty()) {
            $taunting = $companions->first(fn (BattleCompanion $bc) => app(StatusEffectService::class)->isTaunting($battle, 'companion', $bc->id));
            if ($taunting) {
                return ['type' => 'companion', 'label' => $taunting->tamedCompanion?->name ?? 'your companion'];
            }

            if ($role === 'skirmisher') {
                return ['type' => 'dynamic', 'label' => 'Lowest HP%'];
            }
        }

        $unit = $this->topThreatUnit($battle, $targetType, $targetId);
        if ($unit['type'] === 'player') {
            return ['type' => 'player', 'label' => 'You'];
        }

        $companion = $companions->firstWhere('id', $unit['id']) ?? BattleCompanion::with('tamedCompanion')->find($unit['id']);

        return ['type' => 'companion', 'label' => $companion?->tamedCompanion?->name ?? 'your companion'];
    }
}
