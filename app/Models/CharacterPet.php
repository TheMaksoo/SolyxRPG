<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterPet extends Model
{
    protected $fillable = ['character_id', 'pet_id', 'level', 'active'];
    protected $casts = ['active' => 'boolean'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
