<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterCosmetic extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'cosmetic_id', 'unlocked_at'];
    protected $casts = ['unlocked_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function cosmetic(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class);
    }
}
