<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterAchievement extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'achievement_id', 'earned_at'];
    protected $casts = ['earned_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }
}
