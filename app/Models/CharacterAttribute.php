<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterAttribute extends Model
{
    protected $fillable = [
        'character_id', 'damage', 'armor', 'hp_cap', 'hp_regen', 'mana_cap', 'mana_regen',
        'crit', 'crit_damage', 'luck', 'dodge', 'energy_cap', 'energy_regen',
        'mining_speed', 'chopping_speed', 'smelting_speed', 'crafting_speed', 'foraging_speed',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
