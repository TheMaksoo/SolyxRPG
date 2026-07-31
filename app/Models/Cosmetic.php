<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cosmetic extends Model
{
    protected $fillable = ['key', 'type', 'name', 'value', 'icon', 'accent_hex', 'rarity', 'category', 'cost_gems', 'unlock_quest_key', 'unlock_event', 'enabled', 'is_dynamic'];
    protected $casts = ['enabled' => 'boolean', 'is_dynamic' => 'boolean'];
}
