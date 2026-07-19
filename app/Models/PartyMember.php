<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyMember extends Model
{
    public $timestamps = false;
    protected $fillable = ['party_id', 'character_id', 'joined_at'];
    protected $casts = ['joined_at' => 'datetime'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
