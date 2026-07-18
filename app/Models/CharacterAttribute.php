<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterAttribute extends Model
{
    protected $fillable = ['character_id', 'damage', 'armor', 'hp', 'mp', 'crit'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
