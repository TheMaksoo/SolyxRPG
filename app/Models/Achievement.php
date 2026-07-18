<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    protected $fillable = ['key', 'name', 'glyph', 'description', 'requirement_json', 'enabled'];
    protected $casts = ['requirement_json' => 'array', 'enabled' => 'boolean'];
}
