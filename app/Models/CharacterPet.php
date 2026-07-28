<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterPet extends Model
{
    /** Level cap within a single rank — hitting this (at the current rank) is what unlocks Rank Up. */
    public const BASE_MAX_LEVEL = 10;

    /** Extra levels the cap grows by per rank, so higher ranks stay a real level-up grind too. */
    public const LEVELS_PER_RANK = 5;

    /** A pet's bonus is "fully grown" once it hits this rank — no further Rank Up past it. */
    public const MAX_RANK = 50;

    /** Permanent % of a pet's base bonus added per rank — the only thing that ever grows a pet's bonus. */
    private const RANK_BONUS_STEP_PCT = 25;

    protected $fillable = ['character_id', 'pet_id', 'level', 'rank', 'xp', 'active'];
    protected $casts = ['active' => 'boolean'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public static function xpForLevel(int $level): int
    {
        return 40 * $level;
    }

    /** The level cap for a given rank — grows by LEVELS_PER_RANK each time, starting from BASE_MAX_LEVEL. */
    public static function maxLevelForRank(int $rank): int
    {
        return self::BASE_MAX_LEVEL + $rank * self::LEVELS_PER_RANK;
    }

    public function maxLevel(): int
    {
        return self::maxLevelForRank($this->rank);
    }

    public function canRankUp(): bool
    {
        return $this->rank < self::MAX_RANK && $this->level >= $this->maxLevel();
    }

    /** Permanent multiplier from ranking up — level itself grants no bonus (it's purely the grind gate
     * that unlocks the next Rank Up), so a rank-0 pet always sits at its plain base bonus regardless of
     * level, and only ranking up ever grows it. */
    public function rankMultiplier(): float
    {
        return 1 + $this->rank * (self::RANK_BONUS_STEP_PCT / 100);
    }

    /** Total bonus multiplier shown to the player and applied in combat/gathering/crafting. */
    public function bonusMultiplier(): float
    {
        return $this->rankMultiplier();
    }

    /** Gold cost to advance from the current rank to the next one — the pet's own unlock_gold (or, for
     * gems-unlocked pets, a gold-equivalent base) compounding 10% per rank so later ranks cost more than
     * the first. Ranking up is deliberately gold-driven even for gem-unlocked pets, per design: gems stay
     * a small top-up (see gemCostForNextRank), not the primary cost. */
    public function goldCostForNextRank(): int
    {
        $base = $this->pet->unlock_gold ?? round(($this->pet->unlock_gems ?? 0) * 200);

        return (int) round($base * (1.10 ** $this->rank));
    }

    /** Gem cost to advance to the next rank — zero for gold-unlocked pets. For gem-unlocked pets, a small
     * top-up (10% of the pet's own unlock_gems) on top of the gold cost above, compounding the same 10%
     * per rank, so gems stay relevant to premium pets without rank-up being pay-to-win. */
    public function gemCostForNextRank(): int
    {
        if (! $this->pet->unlock_gems) {
            return 0;
        }

        return (int) round($this->pet->unlock_gems * 0.10 * (1.10 ** $this->rank));
    }
}
