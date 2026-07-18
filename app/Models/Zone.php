<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $fillable = ['key', 'name', 'glyph', 'danger', 'min_level', 'locked', 'enabled', 'tester_only', 'sort_order'];

    protected $casts = [
        'locked' => 'boolean',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];

    public function monsters(): HasMany
    {
        return $this->hasMany(Monster::class);
    }
}
