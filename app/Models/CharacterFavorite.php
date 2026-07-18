<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterFavorite extends Model
{
    protected $fillable = ['character_id', 'favorite_character_id'];
}
