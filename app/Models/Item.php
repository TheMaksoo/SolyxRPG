<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    /** Every numeric combat/effect stat an item can carry, as real columns instead of one JSON blob —
     * lets a GM balance-editor role bind directly to a named field (e.g. `atk`) instead of hand-editing
     * JSON (see resourceSchemas.js's items entry). getStatJsonAttribute()/setStatJsonAttribute() below
     * still expose/accept the old ['atk' => 42, ...] shape as a virtual `stat_json` attribute, so every
     * existing reader (CombatService, GmAnalyticsController::itemBalance()'s stat_json loop, the
     * frontend's item displays, etc.) keeps working unchanged. */
    private const STAT_KEYS = [
        'atk', 'def', 'crit', 'dodge_pct', 'mp', 'mana_regen_flat', 'luck', 'lifesteal_pct',
        'heal_hp_flat', 'heal_hp_pct', 'heal_mp_flat', 'heal_mp_pct',
        'hp_regen_pct_buff', 'mana_regen_pct_buff', 'duration_seconds',
        'atk_pct_buff', 'buff_fights', 'revive_chance_pct', 'pet_xp',
        'gather_speed_pct', 'gather_yield_bonus', 'craft_speed_pct',
    ];

    protected $fillable = [
        'key', 'name', 'type', 'weapon_category', 'class_key', 'rarity', 'min_level', 'glyph', 'sprite', 'description',
        'roll_pct', 'price_gold', 'price_gems', 'enabled', 'tester_only',
        'atk', 'def', 'crit', 'dodge_pct', 'mp', 'mana_regen_flat', 'luck', 'lifesteal_pct',
        'heal_hp_flat', 'heal_hp_pct', 'heal_mp_flat', 'heal_mp_pct',
        'hp_regen_pct_buff', 'mana_regen_pct_buff', 'duration_seconds',
        'atk_pct_buff', 'buff_fights', 'revive_chance_pct', 'pet_xp',
        'gather_speed_pct', 'gather_yield_bonus', 'craft_speed_pct',
        // Not a real column (see getStatJsonAttribute()/setStatJsonAttribute()) — kept fillable purely so
        // mass assignment (CraftingController::createCraftedVariant's Item::create(['stat_json' => ...]))
        // still reaches the mutator instead of being silently stripped by Eloquent's fillable filter.
        'stat_json',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];

    // Accessors aren't included in toArray()/toJson() output unless appended — every frontend page that
    // reads `item.stat_json.atk` from an API response depends on this being here, not just on the
    // accessor existing.
    protected $appends = ['stat_json'];

    /** Assembled from the real stat columns, non-null ones only — matches the old JSON blob's shape,
     * where an absent stat was simply a missing key rather than an explicit null/zero. */
    public function getStatJsonAttribute(): array
    {
        $stats = [];
        foreach (self::STAT_KEYS as $key) {
            $value = $this->attributes[$key] ?? null;
            if ($value !== null) {
                $stats[$key] = $value;
            }
        }

        return $stats;
    }

    /** Spreads an incoming ['atk' => 42, ...] array back into the real columns — lets any code still
     * constructing/updating an Item via a `stat_json` key (mass assignment, an old script, etc.) keep
     * working exactly as before. */
    public function setStatJsonAttribute(array $value): void
    {
        foreach (self::STAT_KEYS as $key) {
            $this->attributes[$key] = $value[$key] ?? null;
        }
    }
}
