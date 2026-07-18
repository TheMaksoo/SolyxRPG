<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pet extends Model
{
    protected $fillable = ['key', 'name', 'glyph', 'description', 'bonus_json', 'unlock_gems', 'enabled'];

    protected $casts = [
        'bonus_json' => 'array',
        'enabled' => 'boolean',
    ];
}
