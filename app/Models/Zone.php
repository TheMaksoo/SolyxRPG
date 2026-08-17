<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $fillable = ['key', 'name', 'glyph', 'sprite', 'danger', 'min_level', 'locked', 'enabled', 'tester_only', 'sort_order'];

    protected $casts = [
        'locked' => 'boolean',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];

    public function monsters(): HasMany
    {
        return $this->hasMany(Monster::class);
    }

    /** The enabled zone with the greatest min_level the character's level still clears — the "frontier"
     * a player is expected to be farming. Null if no enabled zone qualifies (e.g. brand-new character
     * below every zone's min_level). Shared by CombatService::zoneFrontierPenaltyPct() and
     * ZoneController::index() so the reward math and the world-map display never disagree. */
    public static function frontierFor(Character $character): ?self
    {
        return static::where('enabled', true)
            ->orderBy('min_level')
            ->get()
            ->filter(fn (Zone $z) => $character->level >= $z->min_level)
            ->last();
    }

    /** GM-tunable reward penalty (%) for farming $fightZone instead of the character's frontier zone —
     * see frontierFor(). Zero for the frontier zone itself or anything at/above it. */
    public static function frontierPenaltyPct(Character $character, ?self $fightZone): float
    {
        if (! $fightZone) {
            return 0;
        }

        $frontier = static::frontierFor($character);
        if (! $frontier) {
            return 0;
        }

        $zones = static::where('enabled', true)->orderBy('min_level')->get()->values();
        $fightIndex = $zones->search(fn (Zone $z) => $z->id === $fightZone->id);
        $frontierIndex = $zones->search(fn (Zone $z) => $z->id === $frontier->id);
        if ($fightIndex === false || $frontierIndex === false) {
            return 0;
        }

        $zonesBehind = max(0, $frontierIndex - $fightIndex);
        if ($zonesBehind <= 0) {
            return 0;
        }

        return min(
            GameConfig::number('zone_reward_penalty_cap_pct', 80),
            $zonesBehind * GameConfig::number('zone_reward_penalty_pct_per_zone', 15)
        );
    }

    /** % bonus added to the flat pet food drop chance for this zone's danger tier — higher zones are
     * the intended source of pet food, not the starter zone. GM-tunable per danger tier. */
    public static function petFoodBonusPct(?string $danger): float
    {
        return match ($danger) {
            'medium' => GameConfig::number('pet_food_drop_zone_medium_bonus_pct', 5),
            'high' => GameConfig::number('pet_food_drop_zone_high_bonus_pct', 15),
            'deadly' => GameConfig::number('pet_food_drop_zone_deadly_bonus_pct', 30),
            default => 0,
        };
    }
}
