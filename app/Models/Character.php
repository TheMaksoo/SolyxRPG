<?php

namespace App\Models;

use App\Services\AttributeService;
use App\Services\SkillService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Character extends Model
{
    /** Both HP and mana regen tick out-of-combat on this same interval. */
    private const REGEN_TICK_SECONDS = 5;

    protected $fillable = [
        'user_id', 'name', 'base_class', 'spec_class', 'profession', 'ascension',
        'avatar', 'level', 'xp', 'gold', 'gems', 'hp', 'hp_max', 'mana', 'mana_max',
        'energy', 'energy_max', 'base_atk', 'base_def', 'skill_points', 'attribute_points', 'current_zone_id',
    ];

    protected function casts(): array
    {
        return [
            'last_regen_at' => 'datetime',
            'last_mana_regen_at' => 'datetime',
            'last_energy_regen_at' => 'datetime',
            'hp_regen_buff_expires_at' => 'datetime',
            'mana_regen_buff_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attributes_(): HasOne
    {
        return $this->hasOne(CharacterAttribute::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class);
    }

    /** Gathering/trade skills — Mining, Woodchopping, Smelting, Crafting. Separate from the combat skill tree and from `profession` (Lv.40 class specialization). */
    public function tradeSkills(): HasMany
    {
        return $this->hasMany(CharacterTradeSkill::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function quests(): HasMany
    {
        return $this->hasMany(CharacterQuest::class);
    }

    public function pets(): HasMany
    {
        return $this->hasMany(CharacterPet::class);
    }

    public function dailyClaim(): HasOne
    {
        return $this->hasOne(DailyClaim::class);
    }

    public function guildMembership(): HasOne
    {
        return $this->hasOne(GuildMember::class);
    }

    public function battlePasses(): HasMany
    {
        return $this->hasMany(BattlePass::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'current_zone_id');
    }

    public function sentFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'requester_id');
    }

    public function receivedFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'addressee_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CharacterFavorite::class);
    }

    public function pvpRecord(): HasOne
    {
        return $this->hasOne(PvpRecord::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(CharacterAchievement::class);
    }

    /** Accepted friendships in either direction, as a collection of the *other* character. */
    public function friends()
    {
        $sent = $this->sentFriendRequests()->where('status', 'accepted')->with('addressee')->get()->pluck('addressee');
        $received = $this->receivedFriendRequests()->where('status', 'accepted')->with('requester')->get()->pluck('requester');

        return $sent->concat($received);
    }

    /** The character's currently active companion pet, if any. */
    public function activePet(): ?CharacterPet
    {
        if ($this->relationLoaded('pets')) {
            return $this->pets->firstWhere('active', true);
        }

        return $this->pets()->where('active', true)->with('pet')->first();
    }

    /** Effective combat stats after attribute points, level, equipped gear, and active pet, per build guide §7 */
    public function effectiveStats(): array
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();

        $equipped = $this->relationLoaded('inventory')
            ? $this->inventory->where('equipped', true)
            : $this->inventory()->where('equipped', true)->with('item')->get();

        $gearAtk = 0;
        $gearDef = 0;
        $gearLuck = 0;
        $gearDodge = 0;
        foreach ($equipped as $slot) {
            $stats = $slot->item->stat_json ?? [];
            $gearAtk += $stats['atk'] ?? 0;
            $gearDef += $stats['def'] ?? 0;
            $gearLuck += $stats['luck'] ?? 0;
            $gearDodge += $stats['dodge_pct'] ?? 0;
        }

        $pet = $this->activePet();
        $petBonus = $pet ? ($pet->pet->bonus_json ?? []) : [];
        $petMult = $pet ? $pet->levelMultiplier() : 0;
        $petAtkPct = ($petBonus['atk_pct'] ?? 0) * $petMult;
        $petDefPct = ($petBonus['def_pct'] ?? 0) * $petMult;
        $petCritPct = ($petBonus['crit_pct'] ?? 0) * $petMult;
        $petXpPct = ($petBonus['xp_pct'] ?? 0) * $petMult;

        $skillPassives = $this->passiveSkillBonuses();

        $effAtk = (int) round(($this->base_atk + $attr->damage * 5 + $gearAtk) * (1 + ($petAtkPct + $skillPassives['atk_pct']) / 100));
        $effDef = (int) round(($this->base_def + $attr->armor * 4 + $gearDef) * (1 + ($petDefPct + $skillPassives['def_pct']) / 100));
        $effHpMax = $this->hp_max + $attr->hp_cap * 30;
        $effMpMax = $this->mana_max + $attr->mana_cap * 20;
        $effEnergyMax = $this->energy_max + ($attr->energy_cap ?? 0) * 15;
        $critChance = 18 + $attr->crit * 2 + $petCritPct;
        $critDamageMult = round(1.8 + ($attr->crit_damage ?? 0) * 0.02, 2);
        $luck = ($attr->luck ?? 0) + $gearLuck + ($this->user?->vipLuckBonus() ?? 0);
        $dodgeChance = (new AttributeService())->dodgeChance(($attr->dodge ?? 0), $gearDodge);

        return [
            'eff_atk' => $effAtk,
            'eff_def' => $effDef,
            'eff_hp_max' => $effHpMax,
            'eff_mp_max' => $effMpMax,
            'eff_energy_max' => $effEnergyMax,
            'crit_chance' => $critChance,
            'crit_damage_mult' => $critDamageMult,
            'luck' => $luck,
            'dodge_chance' => $dodgeChance,
            'pet_xp_bonus_pct' => $petXpPct,
            'has_undying' => $skillPassives['has_undying'],
            'power' => $effAtk * 4 + $effDef * 3 + $effHpMax + $luck * 20,
        ];
    }

    /** Sums the always-on (mp_cost === 0) unlocked skills into ATK%/DEF% bonuses and an Undying flag, each scaled by skill rank. */
    private function passiveSkillBonuses(): array
    {
        $skillService = new SkillService();
        $characterSkills = $this->relationLoaded('skills') ? $this->skills : $this->skills()->with('skill')->get();

        $atkPct = 0;
        $defPct = 0;
        $hasUndying = false;

        foreach ($characterSkills as $characterSkill) {
            $skill = $characterSkill->skill;
            if (! $skill || ! $skillService->isPassive($skill)) {
                continue;
            }

            $atkPct += $skillService->passiveAtkPct($skill, $characterSkill->level);
            $defPct += $skillService->passiveDefPct($skill, $characterSkill->level);
            $hasUndying = $hasUndying || $skillService->hasRevive($skill);
        }

        return ['atk_pct' => $atkPct, 'def_pct' => $defPct, 'has_undying' => $hasUndying];
    }

    /** HP restored per regen tick: base 1, +1 per 15 levels, +1 per 3 points invested in HP Regen, plus VIP/potion bonuses. */
    public function regenPerTick(): int
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();
        $base = 1 + intdiv($this->level, 15) + intdiv($attr->hp_regen, 3) + ($this->user?->vipRegenFlatBonus() ?? 0);
        $pct = ($this->user?->vipRegenPctBonus() ?? 0) + $this->activeBuffPct('hp_regen_buff_pct', 'hp_regen_buff_expires_at');

        return max(1, (int) round($base * (1 + $pct / 100)));
    }

    /** Mana restored per regen tick: base 2, +1 per 15 levels, +1 per 3 points invested in Mana Regen, plus VIP/potion bonuses. */
    public function manaRegenPerTick(): int
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();
        $base = 2 + intdiv($this->level, 15) + intdiv($attr->mana_regen, 3) + ($this->user?->vipRegenFlatBonus() ?? 0);
        $pct = ($this->user?->vipRegenPctBonus() ?? 0) + $this->activeBuffPct('mana_regen_buff_pct', 'mana_regen_buff_expires_at');

        return max(1, (int) round($base * (1 + $pct / 100)));
    }

    /** Reads a temporary regen-buff column pair, returning 0 once the buff has expired. */
    private function activeBuffPct(string $pctAttr, string $expiresAtAttr): float
    {
        $expiresAt = $this->$expiresAtAttr;

        return ($expiresAt && $expiresAt->isFuture()) ? (float) $this->$pctAttr : 0;
    }

    /** Energy restored per regen tick: base 2, +1 per 15 levels, +1 per 3 points invested in Energy Regen, plus VIP bonuses. Regens even mid-battle — it only gates trade skills. */
    public function energyRegenPerTick(): int
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();
        $base = 2 + intdiv($this->level, 15) + intdiv($attr->energy_regen ?? 0, 3) + ($this->user?->vipEnergyFlatBonus() ?? 0);
        $pct = $this->user?->vipEnergyPctBonus() ?? 0;

        return max(1, (int) round($base * (1 + $pct / 100)));
    }

    /** Heals a level-and-attribute-scaled trickle of HP and mana for time spent out of combat, and Energy at all times. */
    public function applyPassiveRegen(): void
    {
        $stats = $this->effectiveStats();
        $dirty = $this->regenResource('energy', 'last_energy_regen_at', $stats['eff_energy_max'], self::REGEN_TICK_SECONDS, $this->energyRegenPerTick());

        if (! Battle::where('character_id', $this->id)->where('status', 'active')->exists()) {
            $dirty = $this->regenResource('hp', 'last_regen_at', $stats['eff_hp_max'], self::REGEN_TICK_SECONDS, $this->regenPerTick()) || $dirty;
            $dirty = $this->regenResource('mana', 'last_mana_regen_at', $stats['eff_mp_max'], self::REGEN_TICK_SECONDS, $this->manaRegenPerTick()) || $dirty;
        }

        if ($dirty) {
            $this->save();
        }
    }

    /** Advances one resource's regen clock/value in place. Returns whether anything changed (needs a save). */
    private function regenResource(string $attr, string $lastAtAttr, int $max, int $tickSeconds, int $perTick): bool
    {
        if ($this->$attr >= $max) {
            if ($this->$lastAtAttr === null) {
                $this->$lastAtAttr = now();

                return true;
            }

            return false;
        }

        $last = $this->$lastAtAttr ?? $this->updated_at ?? now();
        $elapsedSeconds = max(0, now()->getTimestamp() - $last->getTimestamp());
        $ticks = intdiv($elapsedSeconds, $tickSeconds);
        if ($ticks <= 0) {
            return false;
        }

        $this->$attr = min($max, $this->$attr + $ticks * $perTick);
        $this->$lastAtAttr = $last->copy()->addSeconds($ticks * $tickSeconds);

        return true;
    }

    public static function xpForLevel(int $level): int
    {
        $xp = 500;
        for ($i = 1; $i < $level; $i++) {
            $xp = (int) round($xp * 1.3);
        }

        return $xp;
    }
}
