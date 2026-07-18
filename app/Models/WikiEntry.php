<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WikiEntry extends Model
{
    protected $fillable = [
        'category',
        'source_type',
        'source_id',
        'glyph',
        'name',
        'sub',
        'rarity',
        'description',
        'stats',
        'sort_order',
        'enabled',
        'tester_only',
    ];

    protected $casts = [
        'stats' => 'array',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];
}
