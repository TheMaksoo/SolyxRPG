<?php

namespace App\Services;

use App\Models\Skill;

class SkillService
{
    /** Each rank above 1 adds this much of the skill's base magnitude, e.g. rank 3 = base * (1 + 2*0.12). */
    private const PCT_PER_LEVEL = 12;

    public function rankMultiplier(int $level): float
    {
        return 1 + (self::PCT_PER_LEVEL / 100) * max(0, $level - 1);
    }

    /** Total damage multiplier for an active (mp_cost > 0) skill at the given rank. */
    public function damageMultiplier(Skill $skill, int $level): float
    {
        $effect = $skill->effect_json ?? [];
        $base = $effect['dmg_mult'] ?? (isset($effect['atk_pct']) ? 1 + $effect['atk_pct'] / 100 : 1.9);
        $hits = $effect['hits'] ?? 1;

        return $base * $hits * $this->rankMultiplier($level);
    }

    /** Passive % bonus to ATK from an always-on (mp_cost === 0) skill, scaled by rank. 0 if the skill has no atk_pct effect. */
    public function passiveAtkPct(Skill $skill, int $level): float
    {
        $pct = $skill->effect_json['atk_pct'] ?? 0;

        return $pct * $this->rankMultiplier($level);
    }

    /** Passive % bonus to DEF from an always-on (mp_cost === 0) skill, scaled by rank. 0 if the skill has no def_pct effect. */
    public function passiveDefPct(Skill $skill, int $level): float
    {
        $pct = $skill->effect_json['def_pct'] ?? 0;

        return $pct * $this->rankMultiplier($level);
    }

    public function isPassive(Skill $skill): bool
    {
        return $skill->mp_cost <= 0;
    }

    public function hasRevive(Skill $skill): bool
    {
        return (bool) ($skill->effect_json['revive_once'] ?? false);
    }

    /** A heal spell restores HP instead of dealing damage when used in combat — see CombatService::act(). */
    public function isHeal(Skill $skill): bool
    {
        return isset($skill->effect_json['heal_hp_pct']);
    }

    /** % of max HP restored on use, scaled by rank. 0 if the skill isn't a heal. */
    public function healPct(Skill $skill, int $level): float
    {
        $pct = $skill->effect_json['heal_hp_pct'] ?? 0;

        return $pct * $this->rankMultiplier($level);
    }

    /** Exact, rank-scaled description of what this skill does right now — the same numbers combat actually uses. */
    public function describe(Skill $skill, int $level): string
    {
        if ($this->hasRevive($skill)) {
            return 'Survive 1 otherwise-fatal hit per battle';
        }

        if ($this->isHeal($skill)) {
            return sprintf('Restores %s%% max HP on use', $this->trim($this->healPct($skill, $level)));
        }

        if ($this->isPassive($skill)) {
            $parts = [];
            if (isset($skill->effect_json['atk_pct'])) {
                $parts[] = sprintf('+%s%% ATK', $this->trim($this->passiveAtkPct($skill, $level)));
            }
            if (isset($skill->effect_json['def_pct'])) {
                $parts[] = sprintf('+%s%% DEF', $this->trim($this->passiveDefPct($skill, $level)));
            }

            return $parts ? implode(', ', $parts).' (passive)' : 'Passive';
        }

        $mult = $this->damageMultiplier($skill, $level);
        $hits = $skill->effect_json['hits'] ?? 1;
        $hitsText = $hits > 1 ? " ({$hits} hits)" : '';

        return sprintf('%sx ATK damage on use%s', $this->trim($mult), $hitsText);
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
