<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GameConfig;
use Illuminate\Http\Request;

class GmConfigController extends Controller
{
    private const DEFAULT_CONFIG = [
        'gold_mult' => '1',
        'xp_mult' => '1',
        'drop_rate' => '15',
        'gem_mult' => '1',
        'death_gold_loss_pct' => '8',
        'death_xp_loss_pct' => '15',
        'vip_luck_bronze' => '2',
        'vip_luck_gold' => '6',
        'vip_luck_diamond' => '12',
        'vip_regen_flat_bronze' => '1',
        'vip_regen_flat_gold' => '2',
        'vip_regen_flat_diamond' => '4',
        'vip_regen_pct_bronze' => '25',
        'vip_regen_pct_gold' => '50',
        'vip_regen_pct_diamond' => '100',
        'vip_gold_xp_pct_bronze' => '25',
        'vip_gold_xp_pct_gold' => '50',
        'vip_gold_xp_pct_diamond' => '100',
        'vip_craft_speed_pct_bronze' => '25',
        'vip_craft_speed_pct_gold' => '50',
        'vip_craft_speed_pct_diamond' => '100',
        'vip_energy_flat_bronze' => '1',
        'vip_energy_flat_gold' => '2',
        'vip_energy_flat_diamond' => '4',
        'vip_energy_pct_bronze' => '25',
        'vip_energy_pct_gold' => '50',
        'vip_energy_pct_diamond' => '100',
        'vip_auto_pass_pct_bronze' => '25',
        'vip_auto_pass_pct_gold' => '50',
        'vip_auto_pass_pct_diamond' => '100',
        'vip_daily_gem_bonus_bronze' => '1',
        'vip_daily_gem_bonus_gold' => '3',
        'vip_daily_gem_bonus_diamond' => '5',
        'vip_market_fee_reduction_pct_bronze' => '2',
        'vip_market_fee_reduction_pct_gold' => '5',
        'vip_market_fee_reduction_pct_diamond' => '10',
        'vip_gather_speed_pct_bronze' => '25',
        'vip_gather_speed_pct_gold' => '50',
        'vip_gather_speed_pct_diamond' => '100',
        'luck_gather_bonus_factor' => '0.5',
        'luck_gather_bonus_cap_pct' => '50',
        'vip_craft_queue_bonus_bronze' => '1',
        'vip_craft_queue_bonus_gold' => '2',
        'vip_craft_queue_bonus_diamond' => '3',
        'luck_combat_bonus_per_point' => '0.01',
        'luck_combat_bonus_cap' => '0.75',
        'crit_chance_cap_pct' => '75',
        'crit_damage_mult_cap' => '4.0',
        'pvp_min_level' => '10',
        'guild_join_min_level' => '10',
        'tutorial_level_cap' => '3',
        'xp_level_1_to_2' => '50',
        'xp_level_2_to_3' => '100',
        'minigame_level_cap' => '5',
        'luck_xp_bonus_factor' => '0.4',
        'luck_gem_bonus_factor' => '0.5',
        'luck_roll_min_shift_divisor' => '10',
        'luck_roll_max_shift_divisor' => '4',
        'crafted_value_stat_weight' => '6',
        'crafted_value_roll_weight' => '1.5',
        'crafted_value_luck_weight' => '4',
        'crafted_value_min_base' => '100',
        'crafted_value_bonus_cap_mult' => '1.5',
        'crafted_roll_common_min_pct' => '-5',
        'crafted_roll_common_max_pct' => '10',
        'crafted_roll_rare_min_pct' => '0',
        'crafted_roll_rare_max_pct' => '20',
        'crafted_roll_epic_min_pct' => '10',
        'crafted_roll_epic_max_pct' => '35',
        'crafted_roll_legendary_min_pct' => '20',
        'crafted_roll_legendary_max_pct' => '60',
        'crafted_roll_mythic_min_pct' => '25',
        'crafted_roll_mythic_max_pct' => '100',
        'auto_battle_gem_cost_15' => '30',
        'auto_battle_gem_cost_30' => '42',
        'auto_battle_gem_cost_60' => '70',
        'auto_gather_gem_cost_15' => '30',
        'auto_gather_gem_cost_30' => '42',
        'auto_gather_gem_cost_60' => '70',
        'vip_monthly_gems_bronze' => '50',
        'vip_monthly_gems_gold' => '85',
        'vip_monthly_gems_diamond' => '170',
        'durability_max_common' => '100',
        'durability_max_rare' => '160',
        'durability_max_epic' => '240',
        'durability_max_legendary' => '350',
        'durability_max_mythic' => '500',
        'tame_eligibility_base_pct' => '40',
        'tame_eligibility_elite_penalty_pct' => '20',
        'tame_eligibility_zone_high_penalty_pct' => '10',
        'tame_eligibility_zone_deadly_penalty_pct' => '20',
        'tame_success_base_pct' => '50',
        'tame_success_elite_penalty_pct' => '20',
        'tame_success_zone_high_penalty_pct' => '10',
        'tame_success_zone_deadly_penalty_pct' => '20',
        'luck_tame_bonus_per_point' => '1',
        'luck_tame_bonus_cap' => '30',
        'pet_food_drop_chance_pct' => '15',
        'pet_food_drop_zone_medium_bonus_pct' => '5',
        'pet_food_drop_zone_high_bonus_pct' => '15',
        'pet_food_drop_zone_deadly_bonus_pct' => '30',
        'zone_reward_penalty_pct_per_zone' => '15',
        'zone_reward_penalty_cap_pct' => '80',
        'vip_tame_bonus_bronze' => '5',
        'vip_tame_bonus_gold' => '10',
        'vip_tame_bonus_diamond' => '15',
        'tame_roster_cap_bronze' => '6',
        'tame_roster_cap_gold' => '7',
        'tame_roster_cap_diamond' => '8',
        'companion_regen_tick_seconds' => '5',
        'companion_regen_pct_per_tick' => '2',
        'tame_companion_rename_cost_gems' => '300',
        'companion_def_pct_of_base_hp' => '20',
        'companion_revive_hp_pct' => '50',
        'companion_revive_max_attempts' => '2',
        'skill_mastery_unlock_level' => '150',
        'skill_mastery_bonus_levels' => '3',
        'skill_respec_cost_gems' => '1500',
        'reforge_unlock_level' => '200',
        'reforge_max_level' => '5',
        'reforge_bonus_pct_per_level' => '5',
        'reforge_cost_gold_level_1' => '5000',
        'reforge_cost_gold_level_2' => '20000',
        'reforge_cost_gold_level_3' => '55000',
        'reforge_cost_gold_level_4' => '95000',
        'reforge_cost_gold_level_5' => '150000',
        'reforge_cost_gems_level_1' => '10',
        'reforge_cost_gems_level_2' => '20',
        'reforge_cost_gems_level_3' => '35',
        'reforge_cost_gems_level_4' => '45',
        'reforge_cost_gems_level_5' => '60',
        'reforge_cost_material_qty_level_1' => '1',
        'reforge_cost_material_qty_level_2' => '2',
        'reforge_cost_material_qty_level_3' => '3',
        'reforge_cost_material_qty_level_4' => '4',
        'reforge_cost_material_qty_level_5' => '5',
        // Lootbox rarity ladder (common -> rare -> epic -> legendary -> mythic) — see
        // CombatService::rollLootboxRarity()/CrateService. Unlock minutes scale with rarity; drop
        // chance/floor rarity scale with the killed monster's tier; the cascade chance is how likely a
        // drop upgrades one MORE tier past its floor (shrinking by 75% each further step it clears).
        'loot_crate_common_unlock_minutes' => '5',
        'loot_crate_rare_unlock_minutes' => '60',
        'loot_crate_epic_unlock_minutes' => '480',
        'loot_crate_legendary_unlock_minutes' => '1440',
        'loot_crate_mythic_unlock_minutes' => '4320',
        'loot_crate_drop_chance_trash_pct' => '8',
        'loot_crate_drop_chance_elite_pct' => '20',
        'loot_crate_drop_chance_boss_pct' => '100',
        'loot_crate_boss_huge_chance_pct' => '25',
        // Premium (VIP) lootbox perks — see User::VIP_TIER_LOOTBOX_SLOTS/VIP_TIER_LOOTBOX_TIME_REDUCTION_PCT
        // for the hardcoded fallbacks these override.
        'vip_lootbox_extra_slots_bronze' => '1',
        'vip_lootbox_extra_slots_gold' => '2',
        'vip_lootbox_extra_slots_diamond' => '3',
        'vip_lootbox_time_reduction_pct_bronze' => '5',
        'vip_lootbox_time_reduction_pct_gold' => '10',
        'vip_lootbox_time_reduction_pct_diamond' => '15',
        // Enemy-applied debuffs (MonsterAiService::debuffScaleForZone) — off entirely in 'safe' zones,
        // then scaling up with danger so packs/roles bite harder the further a player pushes.
        'enemy_debuff_crit_down_medium' => '5',
        'enemy_debuff_crit_down_high' => '8',
        'enemy_debuff_crit_down_deadly' => '12',
        'enemy_debuff_armor_shred_medium' => '10',
        'enemy_debuff_armor_shred_high' => '20',
        'enemy_debuff_armor_shred_deadly' => '30',
        'enemy_debuff_weaken_medium' => '8',
        'enemy_debuff_weaken_high' => '14',
        'enemy_debuff_weaken_deadly' => '20',
        'enemy_debuff_slow_medium' => '10',
        'enemy_debuff_slow_high' => '16',
        'enemy_debuff_slow_deadly' => '22',
        // Multi-enemy "pack" encounters (CombatService::rollPackMonsters) — chance and group-size range
        // scale with zone danger; only ever rolled from the 2nd zone (danger != 'safe') onward.
        'pack_encounter_chance_pct_medium' => '20',
        'pack_encounter_chance_pct_high' => '30',
        'pack_encounter_chance_pct_deadly' => '40',
        'pack_group_size_min_medium' => '2',
        'pack_group_size_max_medium' => '2',
        'pack_group_size_min_high' => '2',
        'pack_group_size_max_high' => '3',
        'pack_group_size_min_deadly' => '2',
        'pack_group_size_max_deadly' => '4',
        // Bonus reward multiplier for clearing every enemy in a pack encounter (CombatService::resolveWin).
        'pack_clear_bonus_mult' => '1.6',
        // On top of the danger-tier base above, farming behind your own frontier zone (Zone::frontierPenaltyPct
        // — the existing gold/xp reward penalty for outleveled farming) also raises pack odds: every 1% of
        // that reward penalty adds this many percentage points to the pack encounter chance...
        'pack_penalty_chance_scalar' => '0.5',
        // ...and every this-many percent of reward penalty raises the group-size ceiling by one more monster.
        'pack_penalty_pct_per_extra_size' => '25',
    ];

    public function index()
    {
        foreach (self::DEFAULT_CONFIG as $key => $value) {
            GameConfig::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['config' => GameConfig::orderBy('key')->get()]);
    }

    public function update(Request $request, string $key)
    {
        $data = $request->validate(['value' => ['required']]);
        $row = GameConfig::updateOrCreate(['key' => $key], ['value' => (string) $data['value']]);

        AuditLog::record($request->user()->id, 'gm.config.update', 'game_config', $row->id, $data);

        return response()->json(['config' => $row]);
    }
}
