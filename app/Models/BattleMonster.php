<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An "add" fighting alongside the battle's primary monster — only multi-enemy boss encounters
 * (see DungeonService) create these; a normal 1v1 fight has none. Adds only ever basic-attack, they
 * don't run the full ability/cooldown AI the primary monster does. */
class BattleMonster extends Model
{
    public $timestamps = false;
    protected $fillable = ['battle_id', 'monster_id', 'hp', 'hp_max', 'slot', 'defeated_at'];
    protected $casts = ['defeated_at' => 'datetime'];

    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }

    public function monster(): BelongsTo
    {
        return $this->belongsTo(Monster::class);
    }

    public function isAlive(): bool
    {
        return $this->hp > 0;
    }
}
