<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaderboardHallOfFame extends Model
{
    protected $table = 'leaderboard_hall_of_fame';
    public $timestamps = false;

    protected $fillable = ['season_id', 'rank', 'character_id', 'character_name', 'value', 'reward_summary', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function season(): BelongsTo
    {
        return $this->belongsTo(LeaderboardSeason::class, 'season_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
