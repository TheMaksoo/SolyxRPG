<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A small (max PartyController::MAX_SIZE) friend group — lighter-weight than a Guild, no bank, just
 * a shared class-synergy stat bonus (see Character::partyBonuses()), a same-zone reward share on
 * battle wins (see CombatService::grantPartyShare()), and its own chat. */
class Party extends Model
{
    protected $fillable = ['leader_character_id'];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'leader_character_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(PartyMember::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(PartyInvite::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PartyMessage::class)->latest('created_at');
    }
}
