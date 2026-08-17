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
    protected $fillable = ['battle_id', 'tamed_companion_id', 'hp', 'hp_max', 'mp', 'mp_max', 'slot', 'defeated_at'];
    protected $casts = ['defeated_at' => 'datetime'];
    protected $appends = ['statuses', 'ability'];

    /** Read-only description of this companion's role-flavored special move — see
     * MonsterAiService::ROLE_COMPANION_ABILITY. Shown on its battle card so a player can see what it's
     * actually capable of, not just that it hits things. */
    public function getAbilityAttribute(): ?array
    {
        $role = $this->tamedCompanion?->role ?? 'brute';

        return app(\App\Services\MonsterAiService::class)->companionAbility($role);
    }

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

    /** Active buff/debuff pills on this companion — see StatusEffectService::pillsFor(). Deliberately
     * builds a transient, unsaved Battle instance from battle_id rather than touching the real `battle`
     * relation — accessing $this->battle here would lazy-load and CACHE it, and since this accessor
     * itself runs while the parent Battle is being serialized (Battle::battleCompanions -> this ->
     * battle -> Battle::battleCompanions -> ...), that cached relation gets serialized right back into
     * an infinite recursion the moment this model's toArray()/toJson() runs (e.g. every battle API
     * response). pillsFor() only ever reads $battle->id, so a real fetch was never needed anyway. */
    public function getStatusesAttribute(): array
    {
        return app(\App\Services\StatusEffectService::class)->pillsFor((new Battle())->forceFill(['id' => $this->battle_id]), 'companion', $this->id);
    }
}
