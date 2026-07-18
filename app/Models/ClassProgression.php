<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassProgression extends Model
{
    protected $fillable = ['base_class', 'tier', 'key', 'name', 'glyph', 'description', 'level_cap'];
}
