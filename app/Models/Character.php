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

    /** A generous ceiling on how many regen-sync calls (see CharacterController::saveTick) a single
     * character can rack up in a day. A well-behaved client on the real 1-tick-per-second clock, syncing
     * every 5 ticks, tops out around 86400/5 = 17280/day — this leaves headroom for reconnects, multiple
     * tabs, and clock drift, so crossing it is a strong signal of a modified client running its tick loop
     * faster than real time, not normal play. Regen itself can't actually be sped up this way (it's
     * computed from real elapsed wall-clock time in regenResource(), never from a client-reported tick
     * count) — this is a defense-in-depth signal for review, not a gate on the regen math. */
    public const MAX_DAILY_SYNC_CALLS = 20000;

    /** There's no real level cap — this just bounds CombatService::grantXp()'s multi-level-up loop so a
     * pathological xp value can't spin forever. AchievementSeeder's "Transcendent" milestone at level 150
     * is a flavor milestone, not a ceiling: with xpForLevel()'s linear curve, content past level 60 is
     * pure additional attribute/skill-point grind rather than a new curve or new systems, so there's
     * nothing stopping a character from leveling well beyond it. */
    public const MAX_LEVEL = 999999;

    protected $fillable = [
        'user_id', 'name', 'base_class', 'spec_class', 'profession', 'ascension', 'apex_path', 'transcendence',
        'avatar', 'level', 'xp', 'gold', 'quests_completed', 'hp', 'hp_max', 'mana', 'mana_max',
        'energy', 'energy_max', 'base_atk', 'base_def', 'skill_points', 'attribute_points', 'active_deck_json', 'current_zone_id', 'last_action',
        'active_title_id', 'active_color_id', 'active_banner_id', 'active_icon_id', 'active_frame_id',
        'showcased_achievement_ids', 'bio', 'playstyle_tags', 'tutorial_seen', 'tutorial_step',
        'playtime_seconds', 'last_active_at', 'privacy_settings',
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
            'active_deck_json' => 'array',
            'battles_won' => 'integer',
            'battles_lost' => 'integer',
            'bosses_slain' => 'integer',
            // Gathering/crafting counters added in 2026_08_01_000015 — must be cast to integer so the
            // frontend receives proper JSON numbers rather than strings, otherwise arithmetic like
            // totalGathered in ProfilePage.vue silently does string concatenation instead of addition.
            'times_mined' => 'integer',
            'times_chopped' => 'integer',
            'times_smelted' => 'integer',
            'times_foraged' => 'integer',
            'times_crafted' => 'integer',
            'pvp_attempts_used' => 'integer',
            'pvp_wins_today' => 'integer',
            'dungeon_attempts_used' => 'integer',
            'atk_buff_pct' => 'integer',
            'atk_buff_fights_left' => 'integer',
            'hp_regen_buff_pct' => 'integer',
            'mana_regen_buff_pct' => 'integer',
            'tutorial_seen' => 'boolean',
            'tutorial_step' => 'integer',
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
            'auto_gather_expires_at' => 'datetime',
            'auto_gather_last_tick_at' => 'datetime',
            'sync_calls_today' => 'integer',
            'sync_calls_reset_at' => 'datetime',
            'showcased_achievement_ids' => 'array',
            'playstyle_tags' => 'array',
            'playtime_seconds' => 'integer',
            'last_active_at' => 'datetime',
            'privacy_settings' => 'array',
        ];
    }

    /** Defaults match the previous always-public behavior exactly, so a character who's never touched
     * these settings (privacy_settings is null) shows the same profile as before this feature existed —
     * except playtime, which defaults hidden since it's the one field nobody opted into showing before. */
    private const PRIVACY_DEFAULTS = [
        'gear_visible' => true,
        'combat_stats_visible' => true,
        'activity_feed_visibility' => 'public', // 'public' | 'friends' | 'hidden'
        'playtime_visible' => false,
        'open_to_duels' => true,
    ];

    public function privacySetting(string $key)
    {
        return ($this->privacy_settings ?? [])[$key] ?? self::PRIVACY_DEFAULTS[$key];
    }

    public function privacySettingsWithDefaults(): array
    {
        return array_merge(self::PRIVACY_DEFAULTS, $this->privacy_settings ?? []);
    }

    protected $appends = ['calculated_level', 'calculated_xp_min', 'calculated_xp_max', 'account_gems'];

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

    /** Account-wide gems that can be spent by any character on this account. */
    public function getAccountGemsAttribute(): int
    {
        return $this->user ? ($this->user->gems ?? 0) : 0;
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

    public function dungeonRuns(): HasMany
    {
        return $this->hasMany(DungeonRun::class);
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

    public function activeFrame(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'active_frame_id');
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
        $gearCrit = 0;
        $gearMp = 0;
        $hasArmorSlotEquipped = false;
        $setCounts = [];
        foreach ($equipped as $slot) {
            if ($slot->durability_max !== null && $slot->durability <= 0) {
                continue; // broken gear contributes nothing until repaired
            }

            if ($slot->item->set_key) {
                $setCounts[$slot->item->set_key] = ($setCounts[$slot->item->set_key] ?? 0) + 1;
            }

            $stats = $slot->item->stat_json ?? [];
            // Reforge/enchant level (see ReforgeService, level-200+ feature) scales this item's ENTIRE
            // stat_json — including a specialty item's negative tradeoff stat, symmetrically — rather
            // than just its positive stats, since it represents the whole item getting more powerful.
            $enchantMult = 1 + ($slot->enchant_level ?? 0) * GameConfig::number('reforge_bonus_pct_per_level', 5) / 100;
            $gearAtk += ($stats['atk'] ?? 0) * $enchantMult;
            $gearDef += ($stats['def'] ?? 0) * $enchantMult;
            $gearLuck += ($stats['luck'] ?? 0) * $enchantMult;
            $gearDodge += ($stats['dodge_pct'] ?? 0) * $enchantMult;
            $gearLifesteal += ($stats['lifesteal_pct'] ?? 0) * $enchantMult;
            // Gear crit (flat % bonus, e.g. +5 Crit on a dagger) and gear mp (flat mana pool bonus,
            // e.g. +35 Mana on a staff) were previously accumulated but never wired into the final
            // stat formulas — they showed in item tooltips (rarity.js formatStats) but had no effect.
            $gearCrit += ($stats['crit'] ?? 0) * $enchantMult;
            $gearMp += ($stats['mp'] ?? 0) * $enchantMult;
            if ($slot->item->type === 'shield') {
                // Only warriors have shield-type gear (buckler/kite shield/aegis/bulwark — see
                // ItemSeeder) — their 2nd defensive slot alongside regular chest armor.
                $hasArmorSlotEquipped = true;
            }
        }

        // Set bonuses (see ItemSet) — only the single highest piece-count threshold each equipped set
        // meets contributes, not every threshold crossed, so this is a lookup + fold, not a running sum
        // per set. Broken gear was already skipped above, so a set piece that's broken doesn't count
        // toward its own set either.
        $setAtkPct = 0;
        $setDefPct = 0;
        $setCritFlat = 0;
        if ($setCounts) {
            $sets = ItemSet::whereIn('key', array_keys($setCounts))->get()->keyBy('key');
            foreach ($setCounts as $setKey => $count) {
                $bonus = $sets[$setKey]?->bonusFor($count);
                if (! $bonus) {
                    continue;
                }
                $setAtkPct += $bonus['atk_pct'] ?? 0;
                $setDefPct += $bonus['def_pct'] ?? 0;
                $setCritFlat += $bonus['crit_chance_flat'] ?? 0;
            }
        }

        $petAtkPct = 0;
        $petDefPct = 0;
        $petCritPct = 0;
        $petXpPct = 0;
        $petGatherSpeedPct = 0;
        $petCraftSpeedPct = 0;
        $petHpPct = 0;
        $petDodgeFlat = 0;
        foreach ($this->activePets() as $activePet) {
            $bonus = $activePet->pet->bonus_json ?? [];
            $mult = $activePet->bonusMultiplier();
            $petAtkPct += ($bonus['atk_pct'] ?? 0) * $mult;
            $petDefPct += ($bonus['def_pct'] ?? 0) * $mult;
            $petCritPct += ($bonus['crit_pct'] ?? 0) * $mult;
            $petXpPct += ($bonus['xp_pct'] ?? 0) * $mult;
            $petGatherSpeedPct += ($bonus['gather_speed_pct'] ?? 0) * $mult;
            $petCraftSpeedPct += ($bonus['craft_speed_pct'] ?? 0) * $mult;
            $petHpPct += ($bonus['hp_pct'] ?? 0) * $mult;
            $petDodgeFlat += ($bonus['dodge_flat'] ?? 0) * $mult;
        }

        $skillPassives = $this->passiveSkillBonuses();
        $party = $this->partyBonuses();
        $classPassives = $this->classPassiveBonuses($hasArmorSlotEquipped);
        $specPassives = $this->branchPassiveBonuses();
        // Elixir of Power / Phoenix Elixir consumables (see InventoryController::use() and
        // CombatService's in-battle item branch, which set these two columns) — a temporary ATK% buff
        // that lasts a fixed number of *fights* rather than real time, decremented once per battle
        // resolution in CombatService::resolveWin()/resolveLoss().
        $elixirAtkPct = $this->atk_buff_fights_left > 0 ? $this->atk_buff_pct : 0;

        $atkSubtotal = $this->base_atk + $attr->damage * 5 + $gearAtk;
        $effAtk = (int) round($atkSubtotal * (1 + ($petAtkPct + $skillPassives['atk_pct'] + $party['atk_pct'] + $classPassives['atk_pct'] + $specPassives['atk_pct'] + $elixirAtkPct + $setAtkPct) / 100));

        $defSubtotal = $this->base_def + $attr->armor * 4 + $gearDef;
        $effDef = (int) round($defSubtotal * (1 + ($petDefPct + $skillPassives['def_pct'] + $party['def_pct'] + $classPassives['def_pct'] + $classPassives['shield_def_pct'] + $specPassives['def_pct'] + $setDefPct) / 100));

        $hpSubtotal = $this->hp_max + $attr->hp_cap * 30;
        $effHpMax = (int) round($hpSubtotal * (1 + ($classPassives['hp_pct'] + $specPassives['hp_pct'] + $skillPassives['hp_pct'] + $petHpPct) / 100));

        $mpSubtotal = $this->mana_max + $attr->mana_cap * 20 + $gearMp;
        $effMpMax = (int) round($mpSubtotal * (1 + ($party['mp_pct'] + $classPassives['mp_pct'] + $specPassives['mp_pct'] + $skillPassives['mp_pct']) / 100));

        $effEnergyMax = $this->energy_max + ($attr->energy_cap ?? 0) * 15;
        // Both crit stats stack from a lot of independently-tunable sources (attribute points, gear,
        // pets, party, class/spec passives, skills) with no natural ceiling — hard-capped the same way
        // dodge already is (AttributeService::DODGE_CAP_PCT), so no build reaches a guaranteed-crit or
        // absurd crit-multiplier floor/ceiling. Both caps are GM-tunable like every other balance lever.
        $critChanceRaw = 18 + $attr->crit * 2 + $gearCrit + $petCritPct + $party['crit_chance'] + $specPassives['crit_chance_flat'] + $skillPassives['crit_chance_flat'] + $setCritFlat;
        $critChance = min(GameConfig::number('crit_chance_cap_pct', 75), $critChanceRaw);
        $critDamageBase = 1.8 + ($attr->crit_damage ?? 0) * 0.02;
        $critDamageMult = min(GameConfig::number('crit_damage_mult_cap', 4.0), round($critDamageBase * (1 + $skillPassives['crit_damage_pct'] / 100), 2));
        $guildLuckBonusPct = ($this->guildMembership?->guild?->upgradeBonusPct('luck') ?? 0) / 100;
        $luckSubtotal = ($attr->luck ?? 0) + $gearLuck + ($this->user?->vipLuckBonus() ?? 0) + $party['luck'] + $skillPassives['luck_flat'];
        $luck = (int) round($luckSubtotal * (1 + $guildLuckBonusPct));
        $dodgeChance = (new AttributeService())->dodgeChance(($attr->dodge ?? 0), $gearDodge + $classPassives['dodge_flat'] + $specPassives['dodge_flat'] + $skillPassives['dodge_flat'] + $petDodgeFlat);

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
                ['label' => 'Class Branch Passive', 'value' => $specPassives['atk_pct']],
                ['label' => 'Elixir Buff', 'value' => $elixirAtkPct],
                ['label' => 'Set Bonus', 'value' => $setAtkPct],
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
                ['label' => 'Class Branch Passive', 'value' => $specPassives['def_pct']],
                ['label' => 'Set Bonus', 'value' => $setDefPct],
            ]),
            'eff_hp_max' => $this->statSourceBreakdown($effHpMax, [
                ['label' => 'Base', 'value' => $this->hp_max, 'always' => true],
                ['label' => 'Attributes (HP Cap x30)', 'value' => $attr->hp_cap * 30],
            ], $hpSubtotal, [
                ['label' => 'Class Passive', 'value' => $classPassives['hp_pct']],
                ['label' => 'Class Branch Passive', 'value' => $specPassives['hp_pct']],
                ['label' => 'Skill Passives', 'value' => $skillPassives['hp_pct']],
                ['label' => 'Pet Bonus', 'value' => $petHpPct],
            ]),
            'eff_mp_max' => $this->statSourceBreakdown($effMpMax, [
                ['label' => 'Base', 'value' => $this->mana_max, 'always' => true],
                ['label' => 'Attributes (Mana Cap x20)', 'value' => $attr->mana_cap * 20],
                ['label' => 'Gear', 'value' => $gearMp],
            ], $mpSubtotal, [
                ['label' => 'Party Bonus', 'value' => $party['mp_pct']],
                ['label' => 'Class Passive', 'value' => $classPassives['mp_pct']],
                ['label' => 'Class Branch Passive', 'value' => $specPassives['mp_pct']],
            ]),
            'eff_energy_max' => $this->statSourceBreakdown($effEnergyMax, [
                ['label' => 'Base', 'value' => $this->energy_max, 'always' => true],
                ['label' => 'Attributes (Energy Cap x15)', 'value' => ($attr->energy_cap ?? 0) * 15],
            ]),
            'crit_chance' => $this->statSourceBreakdown($critChance, [
                ['label' => 'Base', 'value' => 18, 'always' => true],
                ['label' => 'Attributes (Crit x2)', 'value' => $attr->crit * 2],
                ['label' => 'Gear', 'value' => $gearCrit],
                ['label' => 'Pet Bonus', 'value' => $petCritPct],
                ['label' => 'Party Bonus', 'value' => $party['crit_chance']],
                ['label' => 'Class Branch Passive', 'value' => $specPassives['crit_chance_flat']],
            ]),
            'crit_damage_mult' => $this->statSourceBreakdown($critDamageMult, [
                ['label' => 'Base', 'value' => 1.8, 'always' => true],
                ['label' => 'Attributes (Crit Damage x0.02)', 'value' => ($attr->crit_damage ?? 0) * 0.02],
            ], $critDamageBase, [
                ['label' => 'Skill Tree Passive', 'value' => $skillPassives['crit_damage_pct']],
            ]),
            'luck' => $this->statSourceBreakdown($luck, [
                ['label' => 'Attributes', 'value' => $attr->luck ?? 0, 'always' => true],
                ['label' => 'Gear', 'value' => $gearLuck],
                ['label' => 'VIP Bonus', 'value' => $this->user?->vipLuckBonus() ?? 0],
                ['label' => 'Party Bonus', 'value' => $party['luck']],
                ['label' => 'Skill Tree Passive', 'value' => $skillPassives['luck_flat']],
            ], $luckSubtotal, [
                ['label' => 'Guild Upgrade', 'value' => $guildLuckBonusPct * 100],
            ]),
            'dodge_chance' => $this->statSourceBreakdown($dodgeChance, [
                ['label' => 'Attributes', 'value' => $attr->dodge ?? 0, 'always' => true],
                ['label' => 'Gear', 'value' => $gearDodge],
                ['label' => 'Class Passive', 'value' => $classPassives['dodge_flat']],
                ['label' => 'Class Branch Passive', 'value' => $specPassives['dodge_flat']],
                ['label' => 'Skill Passives', 'value' => $skillPassives['dodge_flat']],
                ['label' => 'Pet Bonus', 'value' => $petDodgeFlat],
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
     * NOTE: none of this reads $this->spec_class — see branchPassiveBonuses() for that layer. */
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

    /** Every branch bonus across the full 5-tier class ladder (t20 Specialization → t50 Profession →
     * t100 Ascension → t150 Apex → t200 Transcendence — see ClassSeeder for the 40 ClassProgression rows
     * these keys match 1:1). Magnitude escalates per tier (t20 ~6-8 → t50 ~8-10 → t100 ~10-12 →
     * t150 ~12-14 → t200 ~14-17, roughly, some spent on a secondary/tradeoff stat) so no later tier makes
     * an earlier one pointless, and each class's two branches keep diverging the same offense-vs-defense/
     * utility way their t20 pair already did. Used by both branchPassiveBonuses() below (the mechanical
     * effect) and ClassProgressionController::index() (the human-readable bonus_text) — the two can never
     * drift since they read the same table. */
    public const BRANCH_BONUSES = [
        // Warrior: offense (berserker → warlord → warmonger → bloodfury → godslayer) vs.
        // defense (guardian → vanguard → titan → aegis_warden → immortal_bulwark).
        'berserker' => ['atk_pct' => 6, 'def_pct' => -4],
        'guardian' => ['def_pct' => 8],
        'warlord' => ['atk_pct' => 9, 'def_pct' => -3],
        'vanguard' => ['def_pct' => 10, 'hp_pct' => 3],
        'titan' => ['def_pct' => 12, 'hp_pct' => 6],
        'warmonger' => ['atk_pct' => 13, 'def_pct' => -5],
        'bloodfury' => ['atk_pct' => 15, 'crit_chance_flat' => 3, 'def_pct' => -5],
        'aegis_warden' => ['def_pct' => 16, 'hp_pct' => 8],
        'godslayer' => ['atk_pct' => 19, 'crit_chance_flat' => 5, 'def_pct' => -6],
        'immortal_bulwark' => ['def_pct' => 22, 'hp_pct' => 12],

        // Mage: offense (shadowmage → necromancer → archmage → dreadlich → worldender) vs.
        // crit/utility (elementalist → stormweaver → voidcaller → stormsovereign → astral_oracle).
        'shadowmage' => ['atk_pct' => 6],
        'elementalist' => ['crit_chance_flat' => 4],
        'necromancer' => ['atk_pct' => 9, 'mp_pct' => 4],
        'stormweaver' => ['crit_chance_flat' => 7, 'mp_pct' => 3],
        'archmage' => ['atk_pct' => 13, 'mp_pct' => 6],
        'voidcaller' => ['crit_chance_flat' => 10, 'dodge_flat' => 4],
        'dreadlich' => ['atk_pct' => 16, 'mp_pct' => 8, 'def_pct' => -4],
        'stormsovereign' => ['crit_chance_flat' => 13, 'mp_pct' => 6],
        'worldender' => ['atk_pct' => 20, 'mp_pct' => 10, 'def_pct' => -6],
        'astral_oracle' => ['crit_chance_flat' => 16, 'dodge_flat' => 6, 'mp_pct' => 6],

        // Rogue: offense (assassin → shadowblade → nightblade → bloodreaper → deathbringer) vs.
        // evasion (trickster → duskrunner → wraith → phantom_dancer → shadowveil_sovereign).
        'assassin' => ['atk_pct' => 4, 'crit_chance_flat' => 4],
        'trickster' => ['dodge_flat' => 6],
        'shadowblade' => ['atk_pct' => 8, 'crit_chance_flat' => 5],
        'duskrunner' => ['dodge_flat' => 10, 'hp_pct' => 2],
        'nightblade' => ['atk_pct' => 13, 'crit_chance_flat' => 8],
        'wraith' => ['dodge_flat' => 14, 'hp_pct' => 4],
        'bloodreaper' => ['atk_pct' => 16, 'crit_chance_flat' => 11, 'def_pct' => -4],
        'phantom_dancer' => ['dodge_flat' => 18, 'hp_pct' => 6],
        'deathbringer' => ['atk_pct' => 20, 'crit_chance_flat' => 14, 'def_pct' => -5],
        'shadowveil_sovereign' => ['dodge_flat' => 22, 'hp_pct' => 10, 'crit_chance_flat' => 4],

        // Ranger: offense (hunter → sharpshooter → stormcaller → windrunner → skysovereign) vs.
        // companion/tank (beastmaster → pathfinder → beastlord → wildwarden → primal_avatar).
        'hunter' => ['atk_pct' => 4],
        'beastmaster' => ['hp_pct' => 6, 'def_pct' => 3],
        'sharpshooter' => ['atk_pct' => 9, 'crit_chance_flat' => 3],
        'pathfinder' => ['hp_pct' => 8, 'dodge_flat' => 4],
        'stormcaller' => ['atk_pct' => 13, 'crit_chance_flat' => 5],
        'beastlord' => ['hp_pct' => 12, 'def_pct' => 6, 'dodge_flat' => 3],
        'windrunner' => ['atk_pct' => 16, 'dodge_flat' => 6, 'crit_chance_flat' => 6],
        'wildwarden' => ['hp_pct' => 16, 'def_pct' => 9],
        'skysovereign' => ['atk_pct' => 20, 'crit_chance_flat' => 9, 'dodge_flat' => 5],
        'primal_avatar' => ['hp_pct' => 20, 'def_pct' => 12, 'dodge_flat' => 6],
    ];

    /** Human-readable mirror of BRANCH_BONUSES, generated once and cached, so the Class Path page can
     * show what every branch at every tier actually does instead of leaving players to pick blind. */
    public static function branchBonusText(string $key): ?string
    {
        if (! isset(self::BRANCH_BONUSES[$key])) {
            return null;
        }

        $labels = ['atk_pct' => 'ATK', 'def_pct' => 'DEF', 'hp_pct' => 'HP', 'mp_pct' => 'MP', 'dodge_flat' => 'Dodge Chance', 'crit_chance_flat' => 'Crit Chance'];
        $parts = [];
        foreach (self::BRANCH_BONUSES[$key] as $stat => $value) {
            $parts[] = sprintf('%+d%% %s', $value, $labels[$stat] ?? $stat);
        }

        return implode(', ', $parts);
    }

    /** Sums BRANCH_BONUSES across all 5 of the character's chosen tier-pick columns (spec_class/
     * profession/ascension/apex_path/transcendence) — replaces the old t20-only subclassPassiveBonuses().
     * A null/unrecognized pick at any tier just contributes zero for that tier, so a character who hasn't
     * reached/chosen a later tier yet still gets full credit for the tiers they have. */
    private function branchPassiveBonuses(): array
    {
        $totals = ['atk_pct' => 0, 'def_pct' => 0, 'hp_pct' => 0, 'mp_pct' => 0, 'dodge_flat' => 0, 'crit_chance_flat' => 0];

        foreach ([$this->spec_class, $this->profession, $this->ascension, $this->apex_path, $this->transcendence] as $key) {
            foreach (self::BRANCH_BONUSES[$key] ?? [] as $stat => $value) {
                $totals[$stat] += $value;
            }
        }

        return $totals;
    }

    /** Sums the always-on (mp_cost === 0) unlocked skills into stat bonuses and an Undying flag, each
     * scaled by skill rank. Reads the same flavor keys as BRANCH_BONUSES (hp_pct/mp_pct/crit_chance_flat/
     * dodge_flat), not just atk_pct/def_pct — see SkillSeeder's trunkNodes(), which themes each signature
     * branch's trunk passives off that same branch's own BRANCH_BONUSES entry. */
    private function passiveSkillBonuses(): array
    {
        $skillService = new SkillService();
        $characterSkills = $this->relationLoaded('skills') ? $this->skills : $this->skills()->with('skill')->get();

        $totals = ['atk_pct' => 0, 'def_pct' => 0, 'hp_pct' => 0, 'mp_pct' => 0, 'dodge_flat' => 0, 'crit_chance_flat' => 0, 'crit_damage_pct' => 0, 'luck_flat' => 0, 'debuff_resist_pct' => 0, 'threat_reduction_pct' => 0];
        $hasUndying = false;

        foreach ($characterSkills as $characterSkill) {
            $skill = $characterSkill->skill;
            if (! $skill || ! $skillService->isPassive($skill)) {
                continue;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] += $skillService->passiveStat($skill, $key, $characterSkill->level);
            }
            $hasUndying = $hasUndying || $skillService->hasRevive($skill);
        }

        return $totals + ['has_undying' => $hasUndying];
    }

    /** % reduction applied to the magnitude of any debuff landing on THIS character — see
     * StatusEffectService::apply(). Currently only granted by Warrior's Foundation hub passive
     * (Warfare Focus), so it's 0 for every other class. */
    public function debuffResistPct(): float
    {
        return $this->passiveSkillBonuses()['debuff_resist_pct'] ?? 0;
    }

    /** % reduction applied to threat THIS character generates toward every enemy — see
     * ThreatService::addDamageThreat/addHealThreatToAllEnemies. Currently only granted by Ranger's
     * Foundation hub passive (Marksmanship Focus): a ranger who's built for sustained DPS shouldn't also
     * be the one every pack fight's enemies gang up on. */
    public function threatReductionPct(): float
    {
        return $this->passiveSkillBonuses()['threat_reduction_pct'] ?? 0;
    }

    /** HP restored per regen tick: base 1, +1 per 15 levels, +1 per 3 points invested in HP Regen, plus VIP/potion bonuses. */
    public function regenPerTick(): int
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();
        $base = 1 + intdiv($this->level, 15) + intdiv($attr->hp_regen, 3) + ($this->user?->vipRegenFlatBonus() ?? 0);
        $pct = ($this->user?->vipRegenPctBonus() ?? 0) + $this->activeBuffPct('hp_regen_buff_pct', 'hp_regen_buff_expires_at');

        return max(1, (int) round($base * (1 + $pct / 100)));
    }

    /** Mana restored per regen tick: base 2, +1 per 15 levels, +1 per 3 points invested in Mana Regen, plus gear/VIP/potion bonuses. */
    public function manaRegenPerTick(): int
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();
        $base = 2 + intdiv($this->level, 15) + intdiv($attr->mana_regen, 3) + $this->gearManaRegenFlat() + ($this->user?->vipRegenFlatBonus() ?? 0);
        $pct = ($this->user?->vipRegenPctBonus() ?? 0) + $this->activeBuffPct('mana_regen_buff_pct', 'mana_regen_buff_expires_at');

        return max(1, (int) round($base * (1 + $pct / 100)));
    }

    /** Sum of the permanent `mana_regen_flat` stat across equipped, unbroken gear (e.g. the mage Trinket
     * line) — kept separate from effectiveStats()'s gear loop since manaRegenPerTick() is called far more
     * often (every regen tick) and effectiveStats() does a lot of unrelated work per call. */
    private function gearManaRegenFlat(): int
    {
        $equipped = $this->relationLoaded('inventory')
            ? $this->inventory->where('equipped', true)
            : $this->inventory()->where('equipped', true)->with('item')->get();

        $total = 0;
        foreach ($equipped as $slot) {
            if ($slot->durability_max !== null && $slot->durability <= 0) {
                continue; // broken gear contributes nothing until repaired
            }

            $enchantMult = 1 + ($slot->enchant_level ?? 0) * GameConfig::number('reforge_bonus_pct_per_level', 5) / 100;
            $total += ($slot->item->stat_json['mana_regen_flat'] ?? 0) * $enchantMult;
        }

        return (int) round($total);
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

    /** Energy restored per regen tick: base 0.5 (half the old base of 1), +1 per 15 levels, +1 per 3 points invested in Energy Regen, plus VIP bonuses. Regens even mid-battle — it only gates trade skills. */
    public function energyRegenPerTick(): int
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();
        $base = 0.5 + intdiv($this->level, 15) + intdiv($attr->energy_regen ?? 0, 3) + ($this->user?->vipEnergyFlatBonus() ?? 0);
        $pct = $this->user?->vipEnergyPctBonus() ?? 0;

        return max(1, (int) round($base * (1 + $pct / 100)));
    }

    /** Heals a level-and-attribute-scaled trickle of HP and mana for time spent out of combat, and Energy at all times. */
    public function applyPassiveRegen(): void
    {
        $stats = $this->effectiveStats();
        $dirty = $this->regenResource('energy', 'last_energy_regen_at', $stats['eff_energy_max'], self::REGEN_TICK_SECONDS, $this->energyRegenPerTick());

        if (! Battle::where('character_id', $this->id)->where('status', 'active')->where('is_auto', false)->exists()) {
            $dirty = $this->regenResource('hp', 'last_regen_at', $stats['eff_hp_max'], self::REGEN_TICK_SECONDS, $this->regenPerTick()) || $dirty;
            $dirty = $this->regenResource('mana', 'last_mana_regen_at', $stats['eff_mp_max'], self::REGEN_TICK_SECONDS, $this->manaRegenPerTick()) || $dirty;
        } else {
            // Passive regen is intentionally paused for the whole of an active manual battle (in-combat
            // HP instead ticks battle.character_hp via CombatService::regenInBattle) — advance both anchor
            // timestamps to now rather than leaving them frozen at whatever they were before the fight
            // started. Otherwise the entire fight's duration reads as "owed" regen time the instant the
            // battle ends and this branch runs again, granting a one-shot catch-up that snaps HP/mana back
            // toward full — exactly the jarring jump this method exists to avoid in the first place.
            $this->last_regen_at = now();
            $this->last_mana_regen_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $this->save();
        }
    }

    /** Records one client tick-sync call against this character's rolling daily budget, resetting the
     * counter the moment it's stale rather than needing a scheduled job to zero it out at midnight.
     * Returns true once the count has blown past MAX_DAILY_SYNC_CALLS — the caller decides what to do
     * with that (currently: log it for GM review). Never blocks or throttles the request itself; that's
     * handled separately by the regen-sync route's rate limiter. */
    public function registerSyncCall(): bool
    {
        $todayStart = now()->startOfDay();
        if (! $this->sync_calls_reset_at || $this->sync_calls_reset_at->lt($todayStart)) {
            $this->sync_calls_today = 0;
            $this->sync_calls_reset_at = $todayStart;
        }

        $this->sync_calls_today += 1;
        $this->save();

        return $this->sync_calls_today > self::MAX_DAILY_SYNC_CALLS;
    }

    /** The most a resource could legitimately be worth right now, given real elapsed time since it was
     * last anchored and its per-tick rate — used by CharacterController::saveTick() to clamp a client-
     * pushed HP/mana/energy value. The client computes its own display continuously (a real number,
     * e.g. 137.4) while this ceiling is computed in discrete REGEN_TICK_SECONDS chunks like the rest of
     * this class — so a well-behaved client's rounded value is always <= this ceiling (never rejected),
     * while a tampered client claiming more than real elapsed time could produce gets capped here. */
    public function maxPlausibleValue(int $current, ?\Illuminate\Support\Carbon $lastAt, int $max, int $perTick): int
    {
        if ($current >= $max) {
            return $max;
        }

        $last = $lastAt ?? $this->updated_at ?? now();
        $elapsedSeconds = max(0, now()->getTimestamp() - $last->getTimestamp());
        $ticks = intdiv($elapsedSeconds, self::REGEN_TICK_SECONDS);

        return min($max, $current + $ticks * $perTick);
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
        // Levels 1-2 are hand-tuned instead of running through stepFor()'s formula: they cover the
        // scripted tutorial fights (see MonsterSeeder's is_tutorial monsters, each a flat 10 xp), and the
        // pacing there needs to land on an exact fight count — 5 fights to reach level 2, 10 more to
        // reach level 3 — rather than whatever the general curve happens to produce. Level 3 onward
        // chains onto stepFor() exactly as before; shifting these two early values only moves every later
        // threshold down by a constant few thousand XP, negligible against the endgame's multiplicative
        // growth.
        $table = [
            1 => (int) GameConfig::number('xp_level_1_to_2', 50),
        ];

        for ($level = 2; $level <= 150; $level++) {

            $previous = $table[$level - 1];

            $growth = $level === 2
                ? (int) GameConfig::number('xp_level_2_to_3', 100)
                : self::stepFor($level);

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
