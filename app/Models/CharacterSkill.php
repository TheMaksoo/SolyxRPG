<?php

namespace App\Models;

use App\Services\SkillService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterSkill extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'skill_id', 'unlocked_at', 'level'];
    protected $casts = ['unlocked_at' => 'datetime'];
    protected $appends = ['effect_description', 'next_rank_effect_description'];

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
}
