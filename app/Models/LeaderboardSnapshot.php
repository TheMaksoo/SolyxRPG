<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaderboardSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['category', 'period', 'period_key', 'character_id', 'baseline_value', 'rank_at_boundary', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
