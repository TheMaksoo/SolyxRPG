<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BattlePass extends Model
{
    protected $fillable = ['season', 'character_id', 'tier', 'xp', 'premium'];
    protected $casts = ['premium' => 'boolean'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
