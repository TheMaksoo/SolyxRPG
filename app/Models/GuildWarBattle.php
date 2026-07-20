<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildWarBattle extends Model
{
    protected $fillable = [
        'guild_a_id', 'guild_b_id', 'guild_a_power', 'guild_b_power',
        'winner_guild_id', 'scheduled_for', 'resolved_at',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function guildA(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_a_id');
    }

    public function guildB(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_b_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'winner_guild_id');
    }
}
