<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Battle extends Model
{
    protected $fillable = ['character_id', 'monster_id', 'grade', 'character_hp', 'monster_hp', 'monster_hp_max', 'status', 'log_json', 'revived_with_skill', 'monster_cooldowns_json', 'skill_cooldowns_json'];
    protected $casts = ['character_id' => 'integer', 'log_json' => 'array', 'monster_cooldowns_json' => 'array', 'skill_cooldowns_json' => 'array'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function monster(): BelongsTo
    {
        return $this->belongsTo(Monster::class);
    }

    /** "Adds" fighting alongside the primary monster in a multi-enemy boss encounter — empty for a normal 1v1 fight. */
    public function battleMonsters(): HasMany
    {
        return $this->hasMany(BattleMonster::class)->orderBy('slot');
    }
}
