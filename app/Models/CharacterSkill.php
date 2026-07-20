<?php

namespace App\Models;

use App\Services\SkillService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterSkill extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'skill_id', 'unlocked_at', 'level', 'cooldown_expires_at'];
    protected $casts = ['unlocked_at' => 'datetime', 'cooldown_expires_at' => 'datetime'];
    protected $appends = ['effect_description', 'next_rank_effect_description', 'cooldown_remaining'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /** Exact, rank-scaled description of this skill's effect right now (the same numbers combat uses). */
    public function getEffectDescriptionAttribute(): ?string
    {
        return $this->skill ? (new SkillService())->describe($this->skill, $this->level) : null;
    }

    /** Preview of the next rank's effect, or null if unranked/already maxed. */
    public function getNextRankEffectDescriptionAttribute(): ?string
    {
        if (! $this->skill || $this->level >= $this->skill->max_level) {
            return null;
        }

        return (new SkillService())->describe($this->skill, $this->level + 1);
    }

    /** Legacy wall-clock cooldown readout — kept for schema/API compatibility, but combat no longer writes
     * to cooldown_expires_at (skill cooldowns are now turn/round-based and tracked per-battle on
     * Battle::skill_cooldowns_json instead, see CombatService::act()), so this reads 0 in practice. */
    public function getCooldownRemainingAttribute(): int
    {
        if (! $this->cooldown_expires_at || $this->cooldown_expires_at->isPast()) {
            return 0;
        }

        return (int) now()->diffInSeconds($this->cooldown_expires_at);
    }
}
