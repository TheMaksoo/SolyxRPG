<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrateOpening extends Model
{
    protected $fillable = [
        'character_id', 'item_id', 'rarity', 'rewards_json',
        'started_at', 'completes_at', 'collected_at',
    ];

    protected $casts = [
        'character_id' => 'integer',
        'rewards_json' => 'array',
        'started_at' => 'datetime',
        'completes_at' => 'datetime',
        'collected_at' => 'datetime',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function isReady(): bool
    {
        return $this->collected_at === null && $this->completes_at->isPast();
    }
}
