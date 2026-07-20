<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cosmetic extends Model
{
    protected $fillable = ['key', 'type', 'name', 'value', 'rarity', 'category', 'cost_gems', 'unlock_quest_key', 'unlock_event', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];
}
