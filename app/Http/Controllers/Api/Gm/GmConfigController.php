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
        'luck_xp_bonus_factor' => '0.4',
        'luck_gem_bonus_factor' => '0.5',
        'luck_roll_min_shift_divisor' => '10',
        'luck_roll_max_shift_divisor' => '4',
        'crafted_value_stat_weight' => '6',
        'crafted_value_roll_weight' => '1.5',
        'crafted_value_luck_weight' => '4',
        'crafted_value_min_base' => '100',
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
        'tame_roster_cap_bronze' => '4',
        'tame_roster_cap_gold' => '5',
        'tame_roster_cap_diamond' => '6',
        'companion_regen_tick_seconds' => '5',
        'companion_regen_pct_per_tick' => '2',
        'tame_companion_rename_cost_gems' => '300',
        'companion_def_pct_of_base_hp' => '20',
        'companion_damage_redirect_pct_per_pet' => '10',
        'companion_damage_redirect_pct_cap' => '90',
        'companion_revive_hp_pct' => '50',
        'companion_revive_max_attempts' => '2',
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
