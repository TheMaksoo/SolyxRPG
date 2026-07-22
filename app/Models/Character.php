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

    /** There's no real level cap — this just bounds CombatService::grantXp()'s multi-level-up loop so a
     * pathological xp value can't spin forever. AchievementSeeder's "Transcendent" milestone at level 150
     * is a flavor milestone, not a ceiling: with xpForLevel()'s linear curve, content past level 60 is
     * pure additional attribute/skill-point grind rather than a new curve or new systems, so there's
     * nothing stopping a character from leveling well beyond it. */
    public const MAX_LEVEL = 999999;

    protected $fillable = [
        'user_id', 'name', 'base_class', 'spec_class', 'profession', 'ascension',
        'avatar', 'level', 'xp', 'gold', 'gems', 'quests_completed', 'hp', 'hp_max', 'mana', 'mana_max',
        'energy', 'energy_max', 'base_atk', 'base_def', 'skill_points', 'attribute_points', 'current_zone_id', 'last_action',
        'active_title_id', 'active_color_id', 'active_banner_id', 'active_icon_id', 'tutorial_seen',
        'pvp_attempts_used', 'pvp_attempts_reset_at', 'last_daily_reward_at',
        'dungeon_attempts_used', 'dungeon_attempts_reset_at',
        'pvp_wins_today', 'pvp_wins_today_date', 'pvp_10_wins_reward_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            // Numeric gameplay fields — without an explicit cast, Eloquent hands these back as PHP strings
            // (Laravel's default MySQL connection emulates prepares, so every column comes back as a string
            // regardless of its SQL type). Left uncast, JS then does string concatenation/lexicographic
            // comparison instead of arithmetic wherever these values reach the frontend — e.g. "5" + 1 becomes
            // "51" instead of 6, and "8" < "20" becomes true. Casting here fixes that at the source.
            'level' => 'integer',
            'xp' => 'integer',
            'gold' => 'integer',
            'gems' => 'integer',
            'quests_completed' => 'integer',
            'hp' => 'integer',
            'hp_max' => 'integer',
            'mana' => 'integer',
            'mana_max' => 'integer',
            'energy' => 'integer',
            'energy_max' => 'integer',
            'base_atk' => 'integer',
            'base_def' => 'integer',
            'skill_points' => 'integer',
            'attribute_points' => 'integer',
            'battles_won' => 'integer',
            'battles_lost' => 'integer',
            'bosses_slain' => 'integer',
            'pvp_attempts_used' => 'integer',
            'pvp_wins_today' => 'integer',
            'dungeon_attempts_used' => 'integer',
            'atk_buff_pct' => 'integer',
            'atk_buff_fights_left' => 'integer',
            'hp_regen_buff_pct' => 'integer',
            'mana_regen_buff_pct' => 'integer',
            'tutorial_seen' => 'boolean',
            'pvp_attempts_reset_at' => 'datetime',
            'last_daily_reward_at' => 'datetime',
            'dungeon_attempts_reset_at' => 'datetime',
            'pvp_wins_today_date' => 'date',
            'pvp_10_wins_reward_at' => 'datetime',
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

    protected $appends = ['calculated_level', 'calculated_xp_min', 'calculated_xp_max'];

    /** Calculate level from cumulative XP — always accurate even if stored level drifts. */
    public function getCalculatedLevelAttribute(): int
    {
        $level = 1;
        while ($level < self::MAX_LEVEL && $this->xp >= self::xpForLevel($level)) {
            $level++;
        }

        return $level;
    }

    /** XP min for calculated level - used for XP bar display. */
    public function getCalculatedXpMinAttribute(): int
    {
        return $this->calculated_level > 1 ? self::xpForLevel($this->calculated_level - 1) : 0;
    }

    /** XP max for calculated level - used for XP bar display. */
    public function getCalculatedXpMaxAttribute(): int
    {
        return self::xpForLevel($this->calculated_level);
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
        $gearLifesteal = 0;
        $hasArmorSlotEquipped = false;
        foreach ($equipped as $slot) {
            if ($slot->durability_max !== null && $slot->durability <= 0) {
                continue; // broken gear contributes nothing until repaired
            }

            $stats = $slot->item->stat_json ?? [];
            $gearAtk += $stats['atk'] ?? 0;
            $gearDef += $stats['def'] ?? 0;
            $gearLuck += $stats['luck'] ?? 0;
            $gearDodge += $stats['dodge_pct'] ?? 0;
            $gearLifesteal += $stats['lifesteal_pct'] ?? 0;
            if ($slot->item->type === 'shield') {
                // Only warriors have shield-type gear (buckler/kite shield/aegis/bulwark — see
                // ItemSeeder) — their 2nd defensive slot alongside regular chest armor.
                $hasArmorSlotEquipped = true;
            }
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
        $classPassives = $this->classPassiveBonuses($hasArmorSlotEquipped);
        $specPassives = $this->subclassPassiveBonuses();
        // Elixir of Power / Phoenix Elixir consumables (see InventoryController::use() and
        // CombatService's in-battle item branch, which set these two columns) — a temporary ATK% buff
        // that lasts a fixed number of *fights* rather than real time, decremented once per battle
        // resolution in CombatService::resolveWin()/resolveLoss().
        $elixirAtkPct = $this->atk_buff_fights_left > 0 ? $this->atk_buff_pct : 0;

        $atkSubtotal = $this->base_atk + $attr->damage * 5 + $gearAtk;
        $effAtk = (int) round($atkSubtotal * (1 + ($petAtkPct + $skillPassives['atk_pct'] + $party['atk_pct'] + $classPassives['atk_pct'] + $specPassives['atk_pct'] + $elixirAtkPct) / 100));

        $defSubtotal = $this->base_def + $attr->armor * 4 + $gearDef;
        $effDef = (int) round($defSubtotal * (1 + ($petDefPct + $skillPassives['def_pct'] + $party['def_pct'] + $classPassives['def_pct'] + $classPassives['shield_def_pct'] + $specPassives['def_pct']) / 100));

        $hpSubtotal = $this->hp_max + $attr->hp_cap * 30;
        $effHpMax = (int) round($hpSubtotal * (1 + ($classPassives['hp_pct'] + $specPassives['hp_pct']) / 100));

        $mpSubtotal = $this->mana_max + $attr->mana_cap * 20;
        $effMpMax = (int) round($mpSubtotal * (1 + ($party['mp_pct'] + $classPassives['mp_pct'] + $specPassives['mp_pct']) / 100));

        $effEnergyMax = $this->energy_max + ($attr->energy_cap ?? 0) * 15;
        $critChance = 18 + $attr->crit * 2 + $petCritPct + $party['crit_chance'] + $specPassives['crit_chance_flat'];
        $critDamageMult = round(1.8 + ($attr->crit_damage ?? 0) * 0.02, 2);
        $guildLuckBonusPct = ($this->guildMembership?->guild?->upgradeBonusPct('luck') ?? 0) / 100;
        $luckSubtotal = ($attr->luck ?? 0) + $gearLuck + ($this->user?->vipLuckBonus() ?? 0) + $party['luck'];
        $luck = (int) round($luckSubtotal * (1 + $guildLuckBonusPct));
        $dodgeChance = (new AttributeService())->dodgeChance(($attr->dodge ?? 0), $gearDodge + $classPassives['dodge_flat'] + $specPassives['dodge_flat']);

        // Power is the sum of every progression axis: gear + attributes (both already baked into eff_atk/
        // eff_def/luck above), plus combat skill investment (previously uncounted) weighted so a fully
        // skilled-up character can meaningfully outrank a geared-but-unskilled one of similar level.
        $skillLevelSum = ($this->relationLoaded('skills') ? $this->skills : $this->skills()->get())->sum('level');
        $power = $effAtk * 4 + $effDef * 3 + $effHpMax + $luck * 20 + $skillLevelSum * 25;

        // Labeled breakdown mirrors the exact math above (same subtotals/percentages, not a second
        // formula) so the Profile page can show players what each final number is made of without ever
        // drifting out of sync with the totals used everywhere else (Battle, PvP, Leaderboard, ...).
        $breakdown = [
            'eff_atk' => $this->statSourceBreakdown($effAtk, [
                ['label' => 'Base', 'value' => $this->base_atk, 'always' => true],
                ['label' => 'Attributes (Damage x5)', 'value' => $attr->damage * 5],
                ['label' => 'Gear', 'value' => $gearAtk],
            ], $atkSubtotal, [
                ['label' => 'Pet Bonus', 'value' => $petAtkPct],
                ['label' => 'Skill Passives', 'value' => $skillPassives['atk_pct']],
                ['label' => 'Party Bonus', 'value' => $party['atk_pct']],
                ['label' => 'Class Passive', 'value' => $classPassives['atk_pct']],
                ['label' => 'Subclass Passive', 'value' => $specPassives['atk_pct']],
                ['label' => 'Elixir Buff', 'value' => $elixirAtkPct],
            ]),
            'eff_def' => $this->statSourceBreakdown($effDef, [
                ['label' => 'Base', 'value' => $this->base_def, 'always' => true],
                ['label' => 'Attributes (Armor x4)', 'value' => $attr->armor * 4],
                ['label' => 'Gear', 'value' => $gearDef],
            ], $defSubtotal, [
                ['label' => 'Pet Bonus', 'value' => $petDefPct],
                ['label' => 'Skill Passives', 'value' => $skillPassives['def_pct']],
                ['label' => 'Party Bonus', 'value' => $party['def_pct']],
                ['label' => 'Class Passive', 'value' => $classPassives['def_pct']],
                ['label' => 'Shield Block', 'value' => $classPassives['shield_def_pct']],
                ['label' => 'Subclass Passive', 'value' => $specPassives['def_pct']],
            ]),
            'eff_hp_max' => $this->statSourceBreakdown($effHpMax, [
                ['label' => 'Base', 'value' => $this->hp_max, 'always' => true],
                ['label' => 'Attributes (HP Cap x30)', 'value' => $attr->hp_cap * 30],
            ], $hpSubtotal, [
                ['label' => 'Class Passive', 'value' => $classPassives['hp_pct']],
                ['label' => 'Subclass Passive', 'value' => $specPassives['hp_pct']],
            ]),
            'eff_mp_max' => $this->statSourceBreakdown($effMpMax, [
                ['label' => 'Base', 'value' => $this->mana_max, 'always' => true],
                ['label' => 'Attributes (Mana Cap x20)', 'value' => $attr->mana_cap * 20],
            ], $mpSubtotal, [
                ['label' => 'Party Bonus', 'value' => $party['mp_pct']],
                ['label' => 'Class Passive', 'value' => $classPassives['mp_pct']],
                ['label' => 'Subclass Passive', 'value' => $specPassives['mp_pct']],
            ]),
            'eff_energy_max' => $this->statSourceBreakdown($effEnergyMax, [
                ['label' => 'Base', 'value' => $this->energy_max, 'always' => true],
                ['label' => 'Attributes (Energy Cap x15)', 'value' => ($attr->energy_cap ?? 0) * 15],
            ]),
            'crit_chance' => $this->statSourceBreakdown($critChance, [
                ['label' => 'Base', 'value' => 18, 'always' => true],
                ['label' => 'Attributes (Crit x2)', 'value' => $attr->crit * 2],
                ['label' => 'Pet Bonus', 'value' => $petCritPct],
                ['label' => 'Party Bonus', 'value' => $party['crit_chance']],
                ['label' => 'Subclass Passive', 'value' => $specPassives['crit_chance_flat']],
            ]),
            'crit_damage_mult' => $this->statSourceBreakdown($critDamageMult, [
                ['label' => 'Base', 'value' => 1.8, 'always' => true],
                ['label' => 'Attributes (Crit Damage x0.02)', 'value' => ($attr->crit_damage ?? 0) * 0.02],
            ]),
            'luck' => $this->statSourceBreakdown($luck, [
                ['label' => 'Attributes', 'value' => $attr->luck ?? 0, 'always' => true],
                ['label' => 'Gear', 'value' => $gearLuck],
                ['label' => 'VIP Bonus', 'value' => $this->user?->vipLuckBonus() ?? 0],
                ['label' => 'Party Bonus', 'value' => $party['luck']],
            ], $luckSubtotal, [
                ['label' => 'Guild Upgrade', 'value' => $guildLuckBonusPct * 100],
            ]),
            'dodge_chance' => $this->statSourceBreakdown($dodgeChance, [
                ['label' => 'Attributes', 'value' => $attr->dodge ?? 0, 'always' => true],
                ['label' => 'Gear', 'value' => $gearDodge],
                ['label' => 'Class Passive', 'value' => $classPassives['dodge_flat']],
                ['label' => 'Subclass Passive', 'value' => $specPassives['dodge_flat']],
            ]),
            'power' => $this->statSourceBreakdown($power, [
                ['label' => 'From Attack (x4)', 'value' => $effAtk * 4, 'always' => true],
                ['label' => 'From Defense (x3)', 'value' => $effDef * 3],
                ['label' => 'From HP Max', 'value' => $effHpMax],
                ['label' => 'From Luck (x20)', 'value' => $luck * 20],
                ['label' => 'From Skill Levels (x25)', 'value' => $skillLevelSum * 25],
            ]),
        ];

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
            'lifesteal_pct' => $gearLifesteal,
            'pet_xp_bonus_pct' => $petXpPct,
            'pet_gather_speed_pct' => $petGatherSpeedPct,
            'pet_craft_speed_pct' => $petCraftSpeedPct,
            'atk_buff_pct' => $elixirAtkPct,
            'atk_buff_fights_left' => $elixirAtkPct > 0 ? $this->atk_buff_fights_left : 0,
            'has_undying' => $skillPassives['has_undying'],
            'party_bonuses' => $party,
            'power' => $power,
            'breakdown' => $breakdown,
        ];
    }

    /** Builds one stat's labeled contribution list from flat (additive) sources plus optional
     * percentage-based sources applied against $subtotal (mirroring the multiplier step in
     * effectiveStats()). Zero-value sources are omitted unless marked 'always'. Any gap between the
     * displayed sources and the real $total (rounding, or a cap like dodge's hard ceiling) is surfaced
     * as a final "Adjustment" line so the breakdown always sums exactly to the total shown elsewhere. */
    private function statSourceBreakdown(int|float $total, array $flatSources, float $subtotal = 0, array $pctSources = []): array
    {
        $sources = [];
        $shownSum = 0.0;

        foreach ($flatSources as $source) {
            $value = $source['value'];
            if (! ($source['always'] ?? false) && abs($value) < 0.0001) {
                continue;
            }
            $sources[] = ['label' => $source['label'], 'value' => $this->roundForDisplay($value)];
            $shownSum += $value;
        }

        foreach ($pctSources as $source) {
            $pct = $source['value'];
            if (abs($pct) < 0.0001) {
                continue;
            }
            $contribution = $subtotal * $pct / 100;
            $sources[] = [
                'label' => "{$source['label']} ({$this->roundForDisplay($pct)}%)",
                'value' => $this->roundForDisplay($contribution),
            ];
            $shownSum += $contribution;
        }

        $diff = $total - $shownSum;
        if (abs($diff) >= 0.05) {
            $sources[] = ['label' => 'Adjustment', 'value' => $this->roundForDisplay($diff)];
        }

        return ['total' => $this->roundForDisplay($total), 'sources' => $sources];
    }

    /** Rounds to 2 decimals for display, then collapses to an int when that loses nothing (e.g. 85.0 -> 85). */
    private function roundForDisplay(int|float $value): int|float
    {
        $rounded = round($value, 2);

        return (floor($rounded) === $rounded) ? (int) $rounded : $rounded;
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

    /** Innate per-class combat identity — distinct from partyBonuses() (rewards party composition
     * diversity, needs a party) and passiveSkillBonuses() (comes from unlocked skills). These are always
     * on just from picking the class, separating the four classes' feel beyond their base_class stat
     * block set once at character creation:
     *   - Rogue: glass-cannon single-target — flat ATK and dodge up, HP/DEF traded away for it.
     *   - Mage: caster identity is the Healing Light skill (see SkillSeeder) plus a mana-reserve bonus.
     *   - Ranger: high-DPS skirmisher — flat ATK and dodge up (skill cooldown reduction is handled
     *     separately in CombatService::act(), since cooldowns are a battle-state concern, not a stat).
     *   - Warrior: already innately tanky via its base HP/DEF (see CharacterController::store()); the
     *     "weapon AND shield" identity is made a felt mechanical choice via shield_def_pct below, which
     *     only turns on while a shield (this class's 2nd, 'shield'-type slot alongside chest armor — see
     *     ItemSeeder) is equipped.
     * NOTE: none of this reads $this->spec_class — see subclassPassiveBonuses() for that layer. */
    private function classPassiveBonuses(bool $hasArmorSlotEquipped): array
    {
        $zero = ['atk_pct' => 0, 'def_pct' => 0, 'shield_def_pct' => 0, 'hp_pct' => 0, 'mp_pct' => 0, 'dodge_flat' => 0];

        return match ($this->base_class) {
            'rogue' => [...$zero, 'atk_pct' => 10, 'def_pct' => -12, 'hp_pct' => -10, 'dodge_flat' => 8],
            'ranger' => [...$zero, 'atk_pct' => 6, 'dodge_flat' => 6],
            'mage' => [...$zero, 'mp_pct' => 10],
            'warrior' => [...$zero, 'shield_def_pct' => $hasArmorSlotEquipped ? 15 : 0],
            default => $zero,
        };
    }

    /** Human-readable mirror of subclassPassiveBonuses() below, so the Skills page can actually show what
     * each t20 subclass choice does mechanically instead of leaving players to pick blind off flavor text
     * alone — see ClassProgressionController::index(). Keep this in sync with subclassPassiveBonuses() by
     * hand; t40/profession and t60/ascension have no entries here since they carry no real bonus yet. */
    public const SUBCLASS_BONUS_TEXT = [
        'berserker' => '+6% ATK, -4% DEF',
        'guardian' => '+8% DEF',
        'shadowmage' => '+6% ATK',
        'elementalist' => '+4% Crit Chance',
        'assassin' => '+4% ATK, +4% Crit Chance',
        'trickster' => '+6% Dodge Chance',
        'hunter' => '+4% ATK',
        'beastmaster' => '+6% HP, +3% DEF',
    ];

    /** Lv.20 subclass identity (Character::spec_class, chosen via chooseProfession('t20', ...) against
     * ClassProgression — see ClassSeeder for the two options per base class). Purely flavor/name/glyph
     * until now; this is where each pair's two subclasses actually diverge mechanically, stacking on top
     * of classPassiveBonuses() above. Null/unrecognized spec_class (not yet chosen, or pre-Lv.20) gets
     * all zeros. NOTE: t40 profession and t60 ascension tiers remain flavor-only for now — only the t20
     * subclass split was in scope here. */
    private function subclassPassiveBonuses(): array
    {
        $zero = ['atk_pct' => 0, 'def_pct' => 0, 'hp_pct' => 0, 'mp_pct' => 0, 'dodge_flat' => 0, 'crit_chance_flat' => 0];

        return match ($this->spec_class) {
            'berserker' => [...$zero, 'atk_pct' => 6, 'def_pct' => -4],
            'guardian' => [...$zero, 'def_pct' => 8],
            'shadowmage' => [...$zero, 'atk_pct' => 6],
            'elementalist' => [...$zero, 'crit_chance_flat' => 4],
            'assassin' => [...$zero, 'atk_pct' => 4, 'crit_chance_flat' => 4],
            'trickster' => [...$zero, 'dodge_flat' => 6],
            'hunter' => [...$zero, 'atk_pct' => 4],
            'beastmaster' => [...$zero, 'hp_pct' => 6, 'def_pct' => 3],
            default => $zero,
        };
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

    /** Ticks the Elixir of Power / Phoenix Elixir ATK buff down by one fight — called once per battle
     * resolution (win or loss) in CombatService, not per action, since "next N fights" counts fights
     * entered, not turns taken. Clears the pct once the counter bottoms out. */
    public function decrementAtkBuffFight(): void
    {
        if ($this->atk_buff_fights_left <= 0) {
            return;
        }

        $this->atk_buff_fights_left -= 1;
        if ($this->atk_buff_fights_left <= 0) {
            $this->atk_buff_pct = 0;
        }
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

    public static function xpForLevel(int $level): int
    {
        static $table = null;

        if ($table === null) {
            $table = self::buildXpTable();
        }

        if ($level <= 150) {
            return $table[$level];
        }

        // Continue endgame scaling after 150
        $last = $table[150];

        return (int) min(
            $last * pow(1.045, $level - 150),
            9e18
        );
    }


    private static function buildXpTable(): array
    {
        $table = [
            1 => 800
        ];

        for ($level = 2; $level <= 150; $level++) {

            $previous = $table[$level - 1];

            $growth = self::stepFor($level);

            $table[$level] = (int) round(
                $previous + $growth
            );
        }

        return $table;
    }


    private static function stepFor(int $level): int
    {
        $base = 1000;
        return (int) round(match (true) {

                // Tutorial
            $level <= 8 =>
                $base * pow(1.18, $level - 1),

            // Unlock: stronger monsters
            $level <= 20 =>
                ($base * 8 )* pow(1.05, $level - 8),

            // Unlock: new zone
            $level <= 35 =>
                ($base * 20) * pow(1.025, $level - 20),

            // Unlock: elite monsters
            $level <= 50 =>
                ($base * 35) * pow(1.0125, $level - 35),

            // Endgame
            default =>
                ($base * 50) * pow(1.0075, $level - 50),
        });
    }
}
