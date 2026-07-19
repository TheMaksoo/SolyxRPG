<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Monster extends Model
{
    protected $fillable = [
        'key', 'name', 'glyph', 'hp', 'atk', 'gold', 'xp', 'gems', 'is_boss', 'is_elite',
        'zone_id', 'loot_table_json', 'skills_json', 'min_level', 'enabled', 'tester_only',
    ];

    protected $casts = [
        'loot_table_json' => 'array',
        'skills_json' => 'array',
        'is_boss' => 'boolean',
        'is_elite' => 'boolean',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
