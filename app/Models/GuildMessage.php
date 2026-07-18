<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildMessage extends Model
{
    public $timestamps = false;
    protected $fillable = ['guild_id', 'character_id', 'body', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
