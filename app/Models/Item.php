<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $fillable = [
        'key', 'name', 'type', 'weapon_category', 'class_key', 'rarity', 'min_level', 'glyph', 'description', 'stat_json',
        'roll_pct', 'price_gold', 'price_gems', 'enabled', 'tester_only',
    ];

    protected $casts = [
        'stat_json' => 'array',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];
}
