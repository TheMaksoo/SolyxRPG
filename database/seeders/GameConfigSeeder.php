<?php

namespace Database\Seeders;

use App\Models\GameConfig;
use Illuminate\Database\Seeder;

class GameConfigSeeder extends Seeder
{
    public function run(): void
    {
        $config = [
            'gold_mult' => '1',
            'xp_mult' => '1',
            'drop_rate' => '15',
            'gem_mult' => '1',
            'death_gold_loss_pct' => '8',
            'death_xp_loss_pct' => '5',
            'vip_luck_bronze' => '2',
            'vip_luck_gold' => '6',
            'vip_luck_diamond' => '12',
            'vip_regen_flat_bronze' => '1',
            'vip_regen_flat_gold' => '2',
            'vip_regen_flat_diamond' => '4',
            'vip_regen_pct_bronze' => '10',
            'vip_regen_pct_gold' => '25',
            'vip_regen_pct_diamond' => '50',
            'vip_gold_xp_pct_bronze' => '10',
            'vip_gold_xp_pct_gold' => '20',
            'vip_gold_xp_pct_diamond' => '35',
            'vip_craft_speed_pct_bronze' => '15',
            'vip_craft_speed_pct_gold' => '30',
            'vip_craft_speed_pct_diamond' => '50',
            'vip_energy_flat_bronze' => '1',
            'vip_energy_flat_gold' => '2',
            'vip_energy_flat_diamond' => '4',
            'vip_energy_pct_bronze' => '10',
            'vip_energy_pct_gold' => '25',
            'vip_energy_pct_diamond' => '50',
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
        ];

        foreach ($config as $key => $value) {
            GameConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
