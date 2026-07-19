<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BattlePass extends Model
{
    protected $fillable = ['season', 'character_id', 'tier', 'xp', 'premium', 'claimed_free_tiers', 'claimed_premium_tiers'];
    protected $casts = [
        'premium' => 'boolean',
        'claimed_free_tiers' => 'array',
        'claimed_premium_tiers' => 'array',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
