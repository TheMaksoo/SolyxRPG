<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaderboardSeason extends Model
{
    protected $fillable = ['season_number', 'name', 'starts_at', 'ends_at', 'is_active'];
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
