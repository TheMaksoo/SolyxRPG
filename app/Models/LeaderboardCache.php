<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaderboardCache extends Model
{
    protected $table = 'leaderboard_cache';
    protected $fillable = ['character_id', 'power', 'rank'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
