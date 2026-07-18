<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dungeon extends Model
{
    protected $fillable = [
        'key', 'name', 'glyph', 'difficulty', 'boss_monster_id', 'min_level',
        'party_size', 'drops_json', 'enabled', 'tester_only',
    ];

    protected $casts = [
        'drops_json' => 'array',
        'enabled' => 'boolean',
        'tester_only' => 'boolean',
    ];

    public function bossMonster(): BelongsTo
    {
        return $this->belongsTo(Monster::class, 'boss_monster_id');
    }
}
