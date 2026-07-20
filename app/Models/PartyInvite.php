<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyInvite extends Model
{
    protected $fillable = ['party_id', 'character_id', 'inviter_character_id'];
    protected $casts = ['party_id' => 'integer', 'character_id' => 'integer', 'inviter_character_id' => 'integer'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'inviter_character_id');
    }
}
