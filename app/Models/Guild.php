<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    protected $fillable = ['name', 'tag', 'level', 'xp_perk', 'war_status', 'owner_id', 'member_cap'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GuildMessage::class)->latest('created_at');
    }
}
