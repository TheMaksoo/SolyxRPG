<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = [
        'branch', 'key', 'name', 'glyph', 'description', 'tier', 'level_req',
        'mp_cost', 'cooldown_seconds', 'max_level', 'rank_levels', 'effect_json', 'class_scope',
    ];

    protected $casts = ['effect_json' => 'array', 'rank_levels' => 'array'];

    /** Character level required to unlock a given rank (1-indexed). Falls back to level_req for rank 1
     * when rank_levels isn't set, so older seed data without a spacing schedule still works. */
    public function levelForRank(int $rank): int
    {
        return $this->rank_levels[$rank - 1] ?? $this->level_req;
    }
}
