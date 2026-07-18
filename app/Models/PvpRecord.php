<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpRecord extends Model
{
    protected $fillable = ['character_id', 'rating', 'wins', 'losses', 'win_streak'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function rank(): string
    {
        return match (true) {
            $this->rating >= 2000 => 'Diamond',
            $this->rating >= 1600 => 'Platinum',
            $this->rating >= 1300 => 'Gold',
            $this->rating >= 1000 => 'Silver',
            default => 'Bronze',
        };
    }
}
