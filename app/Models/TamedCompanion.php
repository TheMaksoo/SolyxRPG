<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A wild monster tamed mid-battle (see CombatService::attemptTame) — fights as its own combatant
 * (own HP, own attack) rather than granting a passive % bonus like the catalog Companions/Pets
 * feature (Pet/CharacterPet). Deliberately a separate table/model from CharacterPet: several existing
 * pet methods assume a catalog-backed `pet_id` and are not null-safe, so this never touches that table. */
class TamedCompanion extends Model
{
    /** Companions level 1-100 (no rank system, unlike catalog pets) — the level cap where stats hit 200% of base. */
    public const MAX_LEVEL = 100;

    protected $fillable = [
        'character_id', 'monster_id', 'name', 'glyph', 'is_elite', 'grade',
        'base_hp', 'base_atk', 'level', 'xp', 'current_hp', 'is_downed', 'revive_attempts_used',
        'last_regen_at', 'active', 'archived_at', 'archived_reason',
    ];

    protected $casts = [
        'is_elite' => 'boolean',
        'active' => 'boolean',
        'is_downed' => 'boolean',
        'last_regen_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    // Appended so battle responses (which serialize this model directly via battleCompanions.tamedCompanion,
    // not through TamedCompanionController::present()) still carry the same computed stats the Companions
    // page shows — the raw base_hp/base_atk snapshot alone isn't what should be displayed anywhere.
    protected $appends = ['effective_hp_max', 'effective_atk', 'effective_def'];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Permanent — death (hp hits 0 in battle) and release both archive the row rather than deleting it,
     * so TamedCompanionLog entries always resolve. Frees the roster slot; the companion no longer counts
     * toward User::tameRosterCap(). */
    public function archive(string $reason): void
    {
        $this->archived_at = now();
        $this->archived_reason = $reason;
        $this->active = false;
        $this->current_hp = 0;
        $this->is_downed = false;
        $this->save();

        TamedCompanionLog::log($this, $reason === 'died' ? 'died' : 'freed');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function monster(): BelongsTo
    {
        return $this->belongsTo(Monster::class);
    }

    public static function xpForLevel(int $level): int
    {
        return 40 * $level;
    }

    public function maxLevel(): int
    {
        return self::MAX_LEVEL;
    }

    /** Linear 50% (level 1) -> 200% (level 100) of the tamed monster's base stats, +25% flat if it was
     * captured from an elite. First-cut numbers — tune via a future GameConfig pass if needed. */
    public function statMultiplier(): float
    {
        $base = 0.5 + (($this->level - 1) / (self::MAX_LEVEL - 1)) * 1.5;

        return $base * ($this->is_elite ? 1.25 : 1.0);
    }

    public function effectiveHpMax(): int
    {
        return (int) round($this->base_hp * $this->statMultiplier());
    }

    public function effectiveAtk(): int
    {
        return (int) round($this->base_atk * $this->statMultiplier());
    }

    /** Monsters have no defense stat of their own to snapshot (Monster only tracks hp/atk), so a
     * companion's Defense is derived as a GM-tunable % of its base HP instead, scaling with level/elite
     * the same way HP and ATK do. Used in place of the player's own eff_def whenever an enemy attack
     * lands on this companion instead of the player — see CombatService::resolveEnemyTurn(). */
    public function effectiveDef(): int
    {
        $pct = \App\Models\GameConfig::number('companion_def_pct_of_base_hp', 20);

        return (int) round($this->base_hp * $pct / 100 * $this->statMultiplier());
    }

    public function getEffectiveHpMaxAttribute(): int
    {
        return $this->effectiveHpMax();
    }

    public function getEffectiveAtkAttribute(): int
    {
        return $this->effectiveAtk();
    }

    public function getEffectiveDefAttribute(): int
    {
        return $this->effectiveDef();
    }

    public function isAlive(): bool
    {
        return $this->current_hp > 0;
    }

    /** Passive out-of-battle HP regen, mirroring Character::regenResource()'s elapsed-tick math (not
     * reusable directly — that method is private and keyed to Character's own attributes). Call before
     * reading current_hp for battle setup or display. Returns whether anything changed (needs a save). */
    public function regenTick(): bool
    {
        if ($this->is_downed) {
            // Downed and awaiting revive — must not passively heal back to life for free while sitting
            // on the Companions page. See TamedCompanionController::revive().
            return false;
        }

        $tickSeconds = (int) \App\Models\GameConfig::number('companion_regen_tick_seconds', 5);
        $pctPerTick = \App\Models\GameConfig::number('companion_regen_pct_per_tick', 2);
        $max = $this->effectiveHpMax();
        $perTick = max(1, (int) round($max * $pctPerTick / 100));

        if ($this->current_hp >= $max) {
            if ($this->last_regen_at === null) {
                $this->last_regen_at = now();

                return true;
            }

            return false;
        }

        $last = $this->last_regen_at ?? $this->updated_at ?? now();
        $elapsedSeconds = max(0, now()->getTimestamp() - $last->getTimestamp());
        $ticks = intdiv($elapsedSeconds, $tickSeconds);
        if ($ticks <= 0) {
            return false;
        }

        $this->current_hp = min($max, $this->current_hp + $ticks * $perTick);
        $this->last_regen_at = $last->copy()->addSeconds($ticks * $tickSeconds);

        return true;
    }
}
