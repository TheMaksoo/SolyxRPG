<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterTradeSkill extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'skill_key', 'level', 'xp', 'last_action_at', 'last_action_target'];
    protected $casts = ['last_action_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
