<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = ['name', 'type', 'reward', 'effect_json', 'starts_at', 'ends_at', 'active'];

    protected $casts = [
        'effect_json' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'active' => 'boolean',
    ];
}
