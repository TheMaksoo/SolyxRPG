<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'name', 'enabled', 'tester_only'];
    protected $casts = ['enabled' => 'boolean', 'tester_only' => 'boolean'];
}
