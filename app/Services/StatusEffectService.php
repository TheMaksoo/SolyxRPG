<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\BattleStatusEffect;
use Illuminate\Support\Collection;

/**
 * Generalized, stackable buff/debuff engine shared by every battle (1v1, dungeon adds, zone packs).
 *
 * A skill (player or monster) never invents its own mechanic — it just references one of the fixed
 * effect keys below plus its own magnitude and duration. Every application is stored as its own stack
 * row (see BattleStatusEffect) rather than merged into an existing one, so two different-magnitude
 * applications of the same effect on one target both stay alive and expire independently while both
 * contribute to the total (e.g. a 20%-for-3-rounds Weaken plus a 40%-for-1-round Weaken sums to 60%
 * weaken for that one overlapping round, then drops back to 20% once the shorter stack expires).
 */
class StatusEffectService
{
    public const WEAKEN = 'weaken';
    public const VULNERABLE = 'vulnerable';
    public const ARMOR_SHRED = 'armor_shred';
    public const CRIT_DOWN = 'crit_down';
    public const EVASION = 'evasion';
    public const HASTE = 'haste';
    public const SLOW = 'slow';
    public const STUN = 'stun';
    public const BURN = 'burn';
    public const SHIELD = 'shield';
    public const TAUNT = 'taunt';
    public const MEND_OVER_TIME = 'mend_over_time';
    public const ATK_UP = 'atk_up';
    public const FREEZE = 'freeze';
    public const ROOT = 'root';
    public const BREAK = 'break';
    public const POISON = 'poison';

    /** Magnitude-scalable debuffs eligible for a target's `debuff_resist_pct` (see Character::
     * debuffResistPct()) — deliberately excludes the binary flag-type effects (stun/freeze/root/taunt),
     * whose "magnitude" isn't a scalar to shrink, and excludes every buff (shield/evasion/haste/
     * mend_over_time/atk_up) since resistance only ever softens something bad landing on you. */
    private const RESISTIBLE_DEBUFFS = [
        self::WEAKEN, self::VULNERABLE, self::ARMOR_SHRED, self::CRIT_DOWN, self::SLOW, self::BURN, self::POISON, self::BREAK,
    ];

    /** Human-readable catalog for the frontend status-pill legend AND the "Status Effects" glossary panel
     * — key => [label, glyph, tone, unit, desc, stacking, polarity]. `desc` is the plain-English mechanical
     * meaning shown as a tooltip on every live pill (see pillsFor()); `stacking` is a second, always-true
     * sentence about how repeated applications of THIS effect behave (durations/magnitudes vary per skill,
     * but the stacking rule per effect type doesn't) — shown in the glossary panel, not on individual pills.
     * `polarity` ('buff'|'debuff'|'utility') is a real classification of who it helps, used by the wiki to
     * group entries meaningfully instead of by `tone` (a decorative color with no semantic meaning — e.g.
     * 'gold' tone appears on both a buff (Haste) and a debuff (Stun)). Taunt/Root are 'utility' since they
     * change targeting/movement rather than swinging a stat up or down. */
    public const CATALOG = [
        self::WEAKEN => ['label' => 'Weaken', 'glyph' => '↓⚔', 'tone' => 'orange', 'unit' => 'pct', 'desc' => "Reduces the target's outgoing damage.", 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'debuff'],
        self::VULNERABLE => ['label' => 'Vulnerable', 'glyph' => '⚠', 'tone' => 'purple', 'unit' => 'pct', 'desc' => 'Increases damage the target takes from every source.', 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'debuff'],
        self::ARMOR_SHRED => ['label' => 'Armor Shred', 'glyph' => '🛡↓', 'tone' => 'red', 'unit' => 'pct', 'desc' => "Reduces the target's effective Defense.", 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'debuff'],
        self::CRIT_DOWN => ['label' => 'Crit Down', 'glyph' => '✦↓', 'tone' => 'gray', 'unit' => 'flat', 'desc' => "Lowers the target's crit chance (flat points).", 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'debuff'],
        self::EVASION => ['label' => 'Evasion', 'glyph' => '💨', 'tone' => 'blue', 'unit' => 'flat', 'desc' => "Raises the target's dodge chance (flat points).", 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'buff'],
        self::HASTE => ['label' => 'Haste', 'glyph' => '⚡', 'tone' => 'gold', 'unit' => 'pct', 'desc' => 'Increases speed — acts sooner in the round order.', 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'buff'],
        self::SLOW => ['label' => 'Slow', 'glyph' => '🐌', 'tone' => 'gray', 'unit' => 'pct', 'desc' => 'Reduces speed — acts later in the round order.', 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'debuff'],
        self::STUN => ['label' => 'Stun', 'glyph' => '💫', 'tone' => 'gold', 'unit' => 'flag', 'desc' => 'Skips this unit\'s next turn entirely, then wears off.', 'stacking' => 'Any stack present skips the next turn, then exactly one stack is consumed — extra stacks queue up further skipped turns.', 'polarity' => 'debuff'],
        self::FREEZE => ['label' => 'Freeze', 'glyph' => '❄', 'tone' => 'blue', 'unit' => 'flag', 'desc' => 'Skips this unit\'s next turn entirely, then thaws.', 'stacking' => 'Mechanically identical to Stun (same skip-a-turn effect), themed for ice/cold skills — any stack present skips the next turn, then one stack is consumed.', 'polarity' => 'debuff'],
        self::BURN => ['label' => 'Burn', 'glyph' => '🔥', 'tone' => 'red', 'unit' => 'flat', 'desc' => 'Flat damage at the start of each round, ignores Defense.', 'stacking' => 'Each application is its own stack, ticking down independently — a skill that says "25% of damage dealt" rolls that amount fresh every time it hits.', 'polarity' => 'debuff'],
        self::POISON => ['label' => 'Poison', 'glyph' => '☠', 'tone' => 'green', 'unit' => 'flag', 'desc' => "Deals a % of the target's own max HP at the start of each round, per stack.", 'stacking' => "Stacks accumulate with every application (no per-stack timer) and the whole pool loses exactly one stack per round — more hits before it starts decaying means more rounds of damage.", 'polarity' => 'debuff'],
        self::SHIELD => ['label' => 'Shield', 'glyph' => '🛡', 'tone' => 'blue', 'unit' => 'flat', 'desc' => 'Absorbs the next hit(s) before HP is touched.', 'stacking' => 'Each application is its own pool, consumed oldest-first — a fresh Shield stacks on top of any that\'s left.', 'polarity' => 'buff'],
        self::TAUNT => ['label' => 'Taunt', 'glyph' => '🎯', 'tone' => 'red', 'unit' => 'flag', 'desc' => 'Enemy attacks are concentrated onto this unit instead of splitting.', 'stacking' => 'Any stack present forces every enemy attack this round; duplicate applications just refresh how long it lasts.', 'polarity' => 'utility'],
        self::BREAK => ['label' => 'Break', 'glyph' => '💥🛡', 'tone' => 'red', 'unit' => 'pct', 'desc' => "Instantly shatters the target's Shield stacks, then increases damage taken like Vulnerable.", 'stacking' => 'The shield-shatter happens once on application; the resulting damage-taken increase stacks and ticks down exactly like Vulnerable.', 'polarity' => 'debuff'],
        self::MEND_OVER_TIME => ['label' => 'Regen', 'glyph' => '💚', 'tone' => 'green', 'unit' => 'flat', 'desc' => 'Flat heal at the start of each round.', 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'buff'],
        self::ATK_UP => ['label' => 'Empowered', 'glyph' => '⚔↑', 'tone' => 'gold', 'unit' => 'pct', 'desc' => "Increases the target's outgoing damage.", 'stacking' => 'Each application is its own stack, ticking down independently — magnitudes add together while stacks overlap.', 'polarity' => 'buff'],
        self::ROOT => ['label' => 'Root', 'glyph' => '🌿⛓', 'tone' => 'gray', 'unit' => 'flag', 'desc' => 'Cannot Flee the battle while rooted.', 'stacking' => 'Any stack present blocks Flee; duplicate applications just refresh how long it lasts.', 'polarity' => 'utility'],
    ];

    /** $targetType is 'player' (targetId always null), 'companion' (targetId = battle_companions.id),
     * or 'monster' (targetId = battle_monsters.id, or 0 for the primary monster slot — it has no add
     * row of its own since its hp lives directly on the battles table). */
    public function apply(Battle $battle, string $targetType, ?int $targetId, string $effectKey, float $magnitude, int $rounds, string $sourceLabel = ''): BattleStatusEffect
    {
        // A resistible debuff landing on the player is softened by their own debuff_resist_pct (see
        // Character::debuffResistPct()) before it's ever stored — every downstream reader (modifiers(),
        // burnDamage(), etc.) just sums whatever magnitude actually got stacked, so resistance doesn't
        // need its own special case anywhere else.
        if ($targetType === 'player' && in_array($effectKey, self::RESISTIBLE_DEBUFFS, true)) {
            $resist = $battle->character?->debuffResistPct() ?? 0;
            if ($resist > 0) {
                $magnitude *= max(0, 1 - $resist / 100);
            }
        }

        // Break's shield-shatter is a one-time side effect of applying it, not an ongoing modifier —
        // happens once here, before the timed Vulnerable-like portion below is stored as its own stack.
        if ($effectKey === self::BREAK) {
            BattleStatusEffect::where('battle_id', $battle->id)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('effect_key', self::SHIELD)
                ->delete();
        }

        return BattleStatusEffect::create([
            'battle_id' => $battle->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'effect_key' => $effectKey,
            'magnitude' => $magnitude,
            'remaining_rounds' => max(1, $rounds),
            'source_label' => $sourceLabel,
        ]);
    }

    public function stacksFor(Battle $battle, string $targetType, ?int $targetId): Collection
    {
        return BattleStatusEffect::where('battle_id', $battle->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->get();
    }

    public function totalMagnitude(Battle $battle, string $targetType, ?int $targetId, string $effectKey): float
    {
        return (float) $this->stacksFor($battle, $targetType, $targetId)->where('effect_key', $effectKey)->sum('magnitude');
    }

    /** Convenience bag of derived multipliers/deltas folded straight into CombatService's existing
     * damage/crit/dodge formula — see that class for exactly where each one is read. */
    public function modifiers(Battle $battle, string $targetType, ?int $targetId): array
    {
        $stacks = $this->stacksFor($battle, $targetType, $targetId);
        $sum = fn (string $key) => (float) $stacks->where('effect_key', $key)->sum('magnitude');

        return [
            // Floored at 10% so a stacked Weaken can never fully zero out a unit's damage output.
            'dmg_dealt_mult' => max(0.1, 1 - $sum(self::WEAKEN) / 100 + $sum(self::ATK_UP) / 100),
            // Break folds into the same slot as Vulnerable (see CATALOG's stacking note) — its shield-
            // shatter already happened once at apply() time, this is just its lingering damage-taken bump.
            'dmg_taken_mult' => 1 + ($sum(self::VULNERABLE) + $sum(self::BREAK)) / 100,
            'def_mult' => max(0, 1 - $sum(self::ARMOR_SHRED) / 100),
            'crit_delta' => $sum(self::CRIT_DOWN),
            'evasion_delta' => $sum(self::EVASION),
            'speed_mult' => 1 + ($sum(self::HASTE) - $sum(self::SLOW)) / 100,
        ];
    }

    /** True if this unit has a Stun OR Freeze stack — the two are mechanically identical (skip the next
     * turn), just themed differently for ice vs. non-elemental skills. */
    public function isStunned(Battle $battle, string $targetType, ?int $targetId): bool
    {
        return $this->stacksFor($battle, $targetType, $targetId)->whereIn('effect_key', [self::STUN, self::FREEZE])->isNotEmpty();
    }

    /** Removes exactly one Stun/Freeze stack (oldest of either kind first) — call once a skipped unit's
     * turn has actually been skipped. */
    public function consumeOneStun(Battle $battle, string $targetType, ?int $targetId): void
    {
        BattleStatusEffect::where('battle_id', $battle->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->whereIn('effect_key', [self::STUN, self::FREEZE])
            ->oldest('id')
            ->first()
            ?->delete();
    }

    public function isTaunting(Battle $battle, string $targetType, ?int $targetId): bool
    {
        return $this->stacksFor($battle, $targetType, $targetId)->where('effect_key', self::TAUNT)->isNotEmpty();
    }

    public function isRooted(Battle $battle, string $targetType, ?int $targetId): bool
    {
        return $this->stacksFor($battle, $targetType, $targetId)->where('effect_key', self::ROOT)->isNotEmpty();
    }

    /** Reduces available `shield` stacks (oldest-first) by $incoming and returns the damage still left
     * to actually apply to HP — call before every HP subtraction. */
    public function consumeShield(Battle $battle, string $targetType, ?int $targetId, float $incoming): float
    {
        if ($incoming <= 0) {
            return $incoming;
        }

        $stacks = BattleStatusEffect::where('battle_id', $battle->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('effect_key', self::SHIELD)
            ->oldest('id')
            ->get();

        $remaining = $incoming;
        foreach ($stacks as $stack) {
            if ($remaining <= 0) {
                break;
            }
            $absorbed = min((float) $stack->magnitude, $remaining);
            $remaining -= $absorbed;
            $left = $stack->magnitude - $absorbed;
            if ($left <= 0) {
                $stack->delete();
            } else {
                $stack->update(['magnitude' => $left]);
            }
        }

        return $remaining;
    }

    /** Flat damage-over-time due this round from `burn` stacks — ignores defense entirely. */
    public function burnDamage(Battle $battle, string $targetType, ?int $targetId): int
    {
        return (int) round($this->totalMagnitude($battle, $targetType, $targetId, self::BURN));
    }

    /** Flat heal-over-time due this round from `mend_over_time` stacks. */
    public function mendAmount(Battle $battle, string $targetType, ?int $targetId): int
    {
        return (int) round($this->totalMagnitude($battle, $targetType, $targetId, self::MEND_OVER_TIME));
    }

    /** Poison damage due this round — unlike every other DoT, Poison scales off the TARGET's own max HP
     * (not the caster's damage, and not a flat authored number) and stacks don't carry a per-stack
     * duration at all (see tick()'s special case below) — the stack count itself is the only thing that
     * decays, by exactly one per round. $maxHp is the target's own max HP (caller already has it handy —
     * eff_hp_max for the player, hp_max for a monster/companion row). */
    public function poisonDamage(Battle $battle, string $targetType, ?int $targetId, int $maxHp): int
    {
        $stacks = (int) round($this->totalMagnitude($battle, $targetType, $targetId, self::POISON));

        return $stacks > 0 ? $stacks * max(1, (int) round($maxHp * 0.15)) : 0;
    }

    /** Ticks every stack for one target down by a round, deleting anything that expires. Call once per
     * target per resolved round, AFTER reading this round's burn/poison/mend amounts above — otherwise a
     * 1-round burn would tick to 0 and vanish before ever dealing its damage. */
    public function tick(Battle $battle, string $targetType, ?int $targetId): void
    {
        $stacks = $this->stacksFor($battle, $targetType, $targetId);

        foreach ($stacks as $stack) {
            // Poison doesn't expire by its own countdown — the whole stack pool instead loses exactly
            // one stack per round regardless of how many rounds any individual application has "left"
            // (see the single oldest-stack delete below), so its remaining_rounds is never decremented.
            if ($stack->effect_key === self::POISON) {
                continue;
            }
            $remaining = $stack->remaining_rounds - 1;
            if ($remaining <= 0) {
                $stack->delete();
            } else {
                $stack->update(['remaining_rounds' => $remaining]);
            }
        }

        $stacks->where('effect_key', self::POISON)->sortBy('id')->first()?->delete();
    }

    /** Every living-or-not participant of a battle as [targetType, targetId] pairs, so CombatService can
     * tick every target's statuses once per resolved round without hardcoding the list at each call site. */
    public function allTargets(Battle $battle): array
    {
        $targets = [['player', null], ['monster', 0]];
        foreach ($battle->battleMonsters as $bm) {
            $targets[] = ['monster', $bm->id];
        }
        foreach ($battle->battleCompanions as $bc) {
            $targets[] = ['companion', $bc->id];
        }

        return $targets;
    }

    /** Display-ready pills for one target — every live stack is its own pill (not summed), so a player
     * can see e.g. two separate Weaken stacks with different remaining durations, matching the "stacks
     * are independent" design. */
    public function pillsFor(Battle $battle, string $targetType, ?int $targetId): array
    {
        return $this->stacksFor($battle, $targetType, $targetId)
            ->map(function (BattleStatusEffect $s) {
                $meta = self::CATALOG[$s->effect_key] ?? ['label' => $s->effect_key, 'glyph' => '●', 'tone' => 'gray', 'unit' => 'flat', 'desc' => ''];

                return [
                    'key' => $s->effect_key,
                    'label' => $meta['label'],
                    'glyph' => $meta['glyph'],
                    'tone' => $meta['tone'],
                    'unit' => $meta['unit'],
                    'desc' => $meta['desc'] ?? '',
                    'magnitude' => $s->magnitude,
                    'rounds' => $s->remaining_rounds,
                ];
            })
            ->values()
            ->all();
    }
}
