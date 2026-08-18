<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WikiEntry extends Model
{
    protected $fillable = [
        'category',
        'group_label',
        'source_type',
        'source_id',
        'glyph',
        'image_path',
        'artist_name',
        'name',
        'sub',
        'rarity',
        'description',
        'stats',
        'details',
        'sort_order',
        'enabled',
        'tester_only',
    ];

    protected $casts = [
        'stats' => 'array',
        'details' => 'array',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];
}
