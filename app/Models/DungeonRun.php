<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DungeonRun extends Model
{
    protected $fillable = ['character_id', 'dungeon_id', 'battle_id', 'stage', 'total_stages', 'status'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function dungeon(): BelongsTo
    {
        return $this->belongsTo(Dungeon::class);
    }

    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }
}
