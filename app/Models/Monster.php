<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Monster extends Model
{
    protected $fillable = [
        'key', 'name', 'glyph', 'sprite', 'hp', 'atk', 'gold', 'xp', 'gems', 'is_boss', 'is_elite', 'role',
        'zone_id', 'is_tutorial', 'loot_table_json', 'skills_json', 'min_level', 'enabled', 'tester_only',
    ];

    protected $casts = [
        'loot_table_json' => 'array',
        'skills_json' => 'array',
        'is_boss' => 'boolean',
        'is_elite' => 'boolean',
        'is_tutorial' => 'boolean',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];

    /** Appended so every serialized Monster (battle.monster, battle.battle_monsters[].monster, …) carries
     * its species-themed status key — lets the frontend know what a monster can actually inflict (see
     * BattlePage's Status Effects glossary) without duplicating MonsterAiService::SPECIES_STATUS client-side. */
    protected $appends = ['status_effect_key'];

    public function getStatusEffectKeyAttribute(): ?string
    {
        return \App\Services\MonsterAiService::speciesStatusKey($this->key);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** Taming this monster's zone only unlocks once the player has meaningfully outgrown that zone, not
     * the moment they can first walk into it — shared by CombatService::attemptTame()'s server-side gate
     * and Battle::getTameUnlockLevelAttribute()'s client-facing hint so they can't drift apart. */
    public function tameUnlockLevel(): int
    {
        return ($this->zone?->min_level ?? 1) + 10;
    }
}
