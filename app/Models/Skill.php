<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = [
        'branch', 'key', 'name', 'glyph', 'description', 'tier', 'level_req',
        'mp_cost', 'max_level', 'effect_json', 'class_scope',
    ];

    protected $casts = ['effect_json' => 'array'];
}
