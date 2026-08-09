<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tamed companion fighting alongside the player in this battle — mirrors BattleMonster on the
 * enemy side. Only created for battles where the character has at least one active TamedCompanion.
 * A companion reaching 0 hp here goes "downed" on its parent TamedCompanion (see
 * CombatService::downCompanion) rather than archiving immediately — the player gets a few Pet Revive
 * Potion attempts (TamedCompanionController::revive) before it's gone for good. */
class BattleCompanion extends Model
{
    public $timestamps = false;
    protected $fillable = ['battle_id', 'tamed_companion_id', 'hp', 'hp_max', 'slot', 'defeated_at'];
    protected $casts = ['defeated_at' => 'datetime'];

    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }

    public function tamedCompanion(): BelongsTo
    {
        return $this->belongsTo(TamedCompanion::class);
    }

    public function isAlive(): bool
    {
        return $this->hp > 0;
    }
}
