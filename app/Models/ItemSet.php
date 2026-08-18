<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemSet extends Model
{
    protected $fillable = ['key', 'name', 'class_key', 'bonus_2pc_json', 'bonus_3pc_json', 'bonus_4pc_json'];

    protected $casts = [
        'bonus_2pc_json' => 'array',
        'bonus_3pc_json' => 'array',
        'bonus_4pc_json' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'set_key', 'key');
    }

    /** The best bonus this set grants at $equippedCount pieces — 4pc includes 3pc's effect and so on,
     * so only the single highest threshold met is returned rather than stacking every tier. */
    public function bonusFor(int $equippedCount): ?array
    {
        return match (true) {
            $equippedCount >= 4 => $this->bonus_4pc_json,
            $equippedCount >= 3 => $this->bonus_3pc_json,
            $equippedCount >= 2 => $this->bonus_2pc_json,
            default => null,
        };
    }
}
