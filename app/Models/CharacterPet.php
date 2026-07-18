<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterPet extends Model
{
    public const MAX_LEVEL = 25;

    protected $fillable = ['character_id', 'pet_id', 'level', 'xp', 'active'];
    protected $casts = ['active' => 'boolean'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public static function xpForLevel(int $level): int
    {
        return 40 * $level;
    }

    /** Bonus multiplier applied to this pet's bonus_json values, scaled by its level. */
    public function levelMultiplier(): float
    {
        return 1 + ($this->level - 1) * 0.1;
    }
}
