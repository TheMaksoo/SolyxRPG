<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quest extends Model
{
    protected $fillable = ['key', 'name', 'description', 'type', 'goal_json', 'reward_json', 'enabled'];

    protected $casts = [
        'goal_json' => 'array',
        'reward_json' => 'array',
        'enabled' => 'boolean',
    ];
}
