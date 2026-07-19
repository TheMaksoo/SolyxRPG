<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeSkillLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'skill_key', 'target_key', 'qty', 'xp', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
