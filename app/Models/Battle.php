<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Battle extends Model
{
    protected $fillable = ['character_id', 'monster_id', 'character_hp', 'monster_hp', 'status', 'log_json'];
    protected $casts = ['log_json' => 'array'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function monster(): BelongsTo
    {
        return $this->belongsTo(Monster::class);
    }
}
