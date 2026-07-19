<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyMessage extends Model
{
    public $timestamps = false;
    protected $fillable = ['party_id', 'character_id', 'body', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
