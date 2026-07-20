<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CraftingJob extends Model
{
    protected $fillable = [
        'character_id', 'recipe_id', 'result_item_id', 'result_qty', 'rarity', 'roll_pct',
        'started_at', 'completes_at', 'collected_at',
    ];

    protected $casts = [
        'character_id' => 'integer',
        'started_at' => 'datetime',
        'completes_at' => 'datetime',
        'collected_at' => 'datetime',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function resultItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'result_item_id');
    }

    public function isReady(): bool
    {
        return $this->collected_at === null && $this->completes_at->isPast();
    }
}
