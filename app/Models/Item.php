<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $fillable = [
        'key', 'name', 'type', 'rarity', 'glyph', 'description', 'stat_json',
        'price_gold', 'price_gems', 'enabled', 'tester_only',
    ];

    protected $casts = [
        'stat_json' => 'array',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];
}
