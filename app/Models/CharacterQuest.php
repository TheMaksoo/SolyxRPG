<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterQuest extends Model
{
    protected $fillable = ['character_id', 'quest_id', 'progress', 'completed', 'claimed'];
    protected $casts = ['completed' => 'boolean', 'claimed' => 'boolean'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }
}
