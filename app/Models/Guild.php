<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    protected $fillable = ['name', 'tag', 'level', 'xp_perk', 'war_status', 'owner_id', 'member_cap', 'bank_gold', 'bank_gems', 'guild_war_points', 'guild_war_points_reset_at'];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'guild_war_points_reset_at' => 'datetime',
    ];

    public const MAX_UPGRADE_TIER = 5;

    /** Gold-sink guild upgrade tracks, paid for out of the shared guild bank.
     * 'cost' is indexed by tier (1-5), the gold price to advance TO that tier.
     * 'bonus_pct' is indexed by tier (1-5), the % bonus granted once that tier is reached. */
    public const UPGRADE_TRACKS = [
        'gold_find' => [
            'column' => 'gold_find_upgrade_tier',
            'cost' => [1 => 5_000, 2 => 25_000, 3 => 100_000, 4 => 350_000, 5 => 1_200_000],
            'bonus_pct' => [1 => 2, 2 => 4, 3 => 6, 4 => 9, 5 => 12],
        ],
        'xp' => [
            'column' => 'xp_upgrade_tier',
            'cost' => [1 => 5_000, 2 => 25_000, 3 => 100_000, 4 => 350_000, 5 => 1_200_000],
            'bonus_pct' => [1 => 2, 2 => 4, 3 => 6, 4 => 9, 5 => 12],
        ],
        'luck' => [
            'column' => 'luck_upgrade_tier',
            'cost' => [1 => 8_000, 2 => 40_000, 3 => 150_000, 4 => 500_000, 5 => 1_800_000],
            'bonus_pct' => [1 => 2, 2 => 4, 3 => 5, 4 => 7, 5 => 10],
        ],
    ];

    /** Gold cost to purchase the given tier (1-5) of an upgrade track, or null if invalid/out of range. */
    public function upgradeCost(string $track, int $tier): ?int
    {
        return self::UPGRADE_TRACKS[$track]['cost'][$tier] ?? null;
    }

    /** The % bonus this guild currently grants from a track, based on its current tier (0 if not upgraded). */
    public function upgradeBonusPct(string $track): int
    {
        $definition = self::UPGRADE_TRACKS[$track] ?? null;
        if (! $definition) {
            return 0;
        }

        $tier = $this->{$definition['column']} ?? 0;

        return $definition['bonus_pct'][$tier] ?? 0;
    }

    /** XP required to advance from $level to $level + 1. Slower curve than character leveling. */
    public static function xpForLevel(int $level): int
    {
        return 100 * $level * $level;
    }

    /** Add guild XP, handling level-ups (with overflow carry) and xp_perk recalculation. Marks the guild active. */
    public function addXp(int $amount): void
    {
        $this->xp += $amount;

        while ($this->xp >= self::xpForLevel($this->level)) {
            $this->xp -= self::xpForLevel($this->level);
            $this->level++;
            $this->xp_perk = min(25, intdiv($this->level, 2));
        }

        $this->last_activity_at = now();
        $this->war_status = 'active';
        $this->save();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GuildMessage::class)->latest('created_at');
    }
}
