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
        'avatar', 'level', 'xp', 'gold', 'gems', 'quests_completed', 'hp', 'hp_max', 'mana', 'mana_max',
        'energy', 'energy_max', 'base_atk', 'base_def', 'skill_points', 'attribute_points', 'current_zone_id',
        'active_title_id', 'active_color_id', 'active_banner_id', 'active_icon_id', 'tutorial_seen',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'tutorial_seen' => 'boolean',
            'last_regen_at' => 'datetime',
            'last_mana_regen_at' => 'datetime',
            'last_energy_regen_at' => 'datetime',
            'hp_regen_buff_expires_at' => 'datetime',
            'mana_regen_buff_expires_at' => 'datetime',
            'auto_battle_expires_at' => 'datetime',
            'auto_battle_last_tick_at' => 'datetime',
            'auto_battle_paused_at' => 'datetime',
            'auto_gather_expires_at' => 'datetime',
            'auto_gather_last_tick_at' => 'datetime',
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

    public function partyMembership(): HasOne
    {
        return $this->hasOne(PartyMember::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(CharacterAchievement::class);
    }

    public function cosmetics(): HasMany
    {
        return $this->hasMany(CharacterCosmetic::class);
    }

    public function activeTitle(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'active_title_id');
    }

    public function activeColor(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'active_color_id');
    }

    public function activeBanner(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'active_banner_id');
    }

    public function activeIcon(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'active_icon_id');
    }

    /** Accepted friendships in either direction, as a collection of the *other* character. */
    public function friends()
    {
        $sent = $this->sentFriendRequests()->where('status', 'accepted')->with('addressee')->get()->pluck('addressee');
        $received = $this->receivedFriendRequests()->where('status', 'accepted')->with('requester')->get()->pluck('requester');

        return $sent->concat($received);
    }

    /** The character's currently active companion pets (level/VIP determine how many can be active at once). */
    public function activePets(): \Illuminate\Support\Collection
    {
        if ($this->relationLoaded('pets')) {
            return $this->pets->where('active', true)->values();
        }

        return $this->pets()->where('active', true)->with('pet')->get();
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
            if ($slot->durability_max !== null && $slot->durability <= 0) {
                continue; // broken gear contributes nothing until repaired
            }

            $stats = $slot->item->stat_json ?? [];
            $gearAtk += $stats['atk'] ?? 0;
            $gearDef += $stats['def'] ?? 0;
            $gearLuck += $stats['luck'] ?? 0;
            $gearDodge += $stats['dodge_pct'] ?? 0;
        }

        $petAtkPct = 0;
        $petDefPct = 0;
        $petCritPct = 0;
        $petXpPct = 0;
        $petGatherSpeedPct = 0;
        $petCraftSpeedPct = 0;
        foreach ($this->activePets() as $activePet) {
            $bonus = $activePet->pet->bonus_json ?? [];
            $mult = $activePet->levelMultiplier();
            $petAtkPct += ($bonus['atk_pct'] ?? 0) * $mult;
            $petDefPct += ($bonus['def_pct'] ?? 0) * $mult;
            $petCritPct += ($bonus['crit_pct'] ?? 0) * $mult;
            $petXpPct += ($bonus['xp_pct'] ?? 0) * $mult;
            $petGatherSpeedPct += ($bonus['gather_speed_pct'] ?? 0) * $mult;
            $petCraftSpeedPct += ($bonus['craft_speed_pct'] ?? 0) * $mult;
        }

        $skillPassives = $this->passiveSkillBonuses();
        $party = $this->partyBonuses();

        $effAtk = (int) round(($this->base_atk + $attr->damage * 5 + $gearAtk) * (1 + ($petAtkPct + $skillPassives['atk_pct'] + $party['atk_pct']) / 100));
        $effDef = (int) round(($this->base_def + $attr->armor * 4 + $gearDef) * (1 + ($petDefPct + $skillPassives['def_pct'] + $party['def_pct']) / 100));
        $effHpMax = $this->hp_max + $attr->hp_cap * 30;
        $effMpMax = (int) round(($this->mana_max + $attr->mana_cap * 20) * (1 + $party['mp_pct'] / 100));
        $effEnergyMax = $this->energy_max + ($attr->energy_cap ?? 0) * 15;
        $critChance = 18 + $attr->crit * 2 + $petCritPct + $party['crit_chance'];
        $critDamageMult = round(1.8 + ($attr->crit_damage ?? 0) * 0.02, 2);
        $guildLuckBonusPct = ($this->guildMembership?->guild?->upgradeBonusPct('luck') ?? 0) / 100;
        $luck = (int) round((($attr->luck ?? 0) + $gearLuck + ($this->user?->vipLuckBonus() ?? 0) + $party['luck']) * (1 + $guildLuckBonusPct));
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
            'pet_gather_speed_pct' => $petGatherSpeedPct,
            'pet_craft_speed_pct' => $petCraftSpeedPct,
            'has_undying' => $skillPassives['has_undying'],
            'party_bonuses' => $party,
            'power' => $effAtk * 4 + $effDef * 3 + $effHpMax + $luck * 20,
        ];
    }

    /** One stat bonus per distinct class present in the character's party (not per member — two warriors
     * in a party don't stack the warrior bonus twice), rewarding a diverse party comp over a stacked one.
     * The class's own member benefits too, same as any party-wide aura. Empty deltas if not in a party. */
    public function partyBonuses(): array
    {
        $bonuses = ['atk_pct' => 0, 'def_pct' => 0, 'mp_pct' => 0, 'crit_chance' => 0, 'luck' => 0];

        $party = $this->partyMembership?->party;
        if (! $party) {
            return $bonuses;
        }

        $classes = $party->members()->with('character')->get()->pluck('character.base_class')->filter()->unique();

        foreach ($classes as $class) {
            match ($class) {
                'warrior' => $bonuses['def_pct'] += 6,
                'mage' => $bonuses['mp_pct'] += 6,
                'rogue' => $bonuses['crit_chance'] += 3,
                'ranger' => $bonuses['luck'] += 3,
                default => null,
            };
        }

        return $bonuses;
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

    /** Energy restored per regen tick: base 1, +1 per 15 levels, +1 per 3 points invested in Energy Regen, plus VIP bonuses. Regens even mid-battle — it only gates trade skills. */
    public function energyRegenPerTick(): int
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();
        $base = 1 + intdiv($this->level, 15) + intdiv($attr->energy_regen ?? 0, 3) + ($this->user?->vipEnergyFlatBonus() ?? 0);
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

    /** Quadratic curve (matches the level-1 cost of the old 1.3x-per-level exponential curve, but stays
     * sane at high level — the old curve needed ~640M cumulative XP to reach level 50; this needs ~6M. */
    public static function xpForLevel(int $level): int
    {
        return 150 * $level * $level + 350;
    }
}
