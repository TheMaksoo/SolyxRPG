<?php

namespace Database\Seeders;

use App\Models\Quest;
use Illuminate\Database\Seeder;

class QuestSeeder extends Seeder
{
    public function run(): void
    {
        $quests = [
            // ---- Daily ----
            ['key' => 'daily_slay_5', 'name' => 'Slay 5 Monsters', 'description' => 'Defeat any 5 monsters today.', 'type' => 'daily', 'goal_json' => ['kind' => 'battles_won', 'target' => 5], 'reward_json' => ['gold' => 200, 'xp' => 150]],
            ['key' => 'daily_slay_10', 'name' => 'Monster Hunter', 'description' => 'Defeat any 10 monsters today.', 'type' => 'daily', 'goal_json' => ['kind' => 'battles_won', 'target' => 10], 'reward_json' => ['gold' => 350, 'xp' => 300]],
            ['key' => 'daily_travel', 'name' => 'Explore a Zone', 'description' => 'Travel to any zone today.', 'type' => 'daily', 'goal_json' => ['kind' => 'zones_visited', 'target' => 1], 'reward_json' => ['gold' => 80]],
            ['key' => 'daily_zone_hop', 'name' => 'Wanderer', 'description' => 'Travel between zones twice today.', 'type' => 'daily', 'goal_json' => ['kind' => 'zones_visited', 'target' => 2], 'reward_json' => ['gold' => 100]],
            ['key' => 'daily_craft_3', 'name' => "Forge's Apprentice", 'description' => 'Collect 3 finished crafts today.', 'type' => 'daily', 'goal_json' => ['kind' => 'items_crafted', 'target' => 3], 'reward_json' => ['gold' => 150, 'xp' => 100]],
            ['key' => 'daily_dungeon_clear', 'name' => 'Dungeon Delver', 'description' => 'Clear any dungeon today.', 'type' => 'daily', 'goal_json' => ['kind' => 'dungeons_cleared', 'target' => 1], 'reward_json' => ['gold' => 250, 'xp' => 200]],
            ['key' => 'daily_pvp_win', 'name' => 'Arena Contender', 'description' => 'Win a PvP match today.', 'type' => 'daily', 'goal_json' => ['kind' => 'pvp_wins', 'target' => 1], 'reward_json' => ['gold' => 180, 'xp' => 150]],
            ['key' => 'daily_gather_10', 'name' => "Gatherer's Errand", 'description' => 'Gather 10 materials today.', 'type' => 'daily', 'goal_json' => ['kind' => 'materials_gathered', 'target' => 10], 'reward_json' => ['gold' => 120, 'xp' => 100]],

            // ---- Weekly ----
            ['key' => 'weekly_dungeon_clear', 'name' => 'Clear a Dungeon', 'description' => 'Clear any dungeon this week.', 'type' => 'weekly', 'goal_json' => ['kind' => 'dungeons_cleared', 'target' => 1], 'reward_json' => ['gems' => 25]],
            ['key' => 'weekly_boss_hunter', 'name' => 'Weekly Bounty', 'description' => 'Defeat 25 monsters this week.', 'type' => 'weekly', 'goal_json' => ['kind' => 'battles_won', 'target' => 25], 'reward_json' => ['gold' => 1000, 'gems' => 15]],
            ['key' => 'weekly_craft_10', 'name' => "Forge's Journeyman", 'description' => 'Collect 10 finished crafts this week.', 'type' => 'weekly', 'goal_json' => ['kind' => 'items_crafted', 'target' => 10], 'reward_json' => ['gold' => 500, 'gems' => 10]],

            // ---- Monthly ----
            ['key' => 'monthly_slay_150', 'name' => "Month's Reckoning", 'description' => 'Defeat 150 monsters this month.', 'type' => 'monthly', 'goal_json' => ['kind' => 'battles_won', 'target' => 150], 'reward_json' => ['gold' => 4000, 'gems' => 60]],
            ['key' => 'monthly_dungeon_8', 'name' => 'Monthly Expedition', 'description' => 'Clear 8 dungeons this month.', 'type' => 'monthly', 'goal_json' => ['kind' => 'dungeons_cleared', 'target' => 8], 'reward_json' => ['gold' => 3000, 'gems' => 50]],
            ['key' => 'monthly_pvp_20', 'name' => "Season's Champion", 'description' => 'Win 20 PvP matches this month.', 'type' => 'monthly', 'goal_json' => ['kind' => 'pvp_wins', 'target' => 20], 'reward_json' => ['gold' => 3500, 'gems' => 55]],

            // ---- Main (level path) ----
            ['key' => 'main_reach_level_10', 'name' => 'Rising Adventurer', 'description' => 'Reach character level 10.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 10], 'reward_json' => ['gold' => 500, 'gems' => 10]],
            ['key' => 'main_reach_level_15', 'name' => 'Steady Climber', 'description' => 'Reach character level 15.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 15], 'reward_json' => ['gold' => 650, 'gems' => 12]],
            ['key' => 'main_reach_level_20', 'name' => 'Seasoned Fighter', 'description' => 'Reach character level 20.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 20], 'reward_json' => ['gold' => 800, 'gems' => 15]],
            ['key' => 'main_reach_level_25', 'name' => 'Proven Adventurer', 'description' => 'Reach character level 25.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 25], 'reward_json' => ['gold' => 900, 'gems' => 18]],
            ['key' => 'main_reach_level_30', 'name' => 'Battle-Hardened', 'description' => 'Reach character level 30.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 30], 'reward_json' => ['gold' => 1200, 'gems' => 20]],
            ['key' => 'main_reach_level_35', 'name' => 'Front-Line Veteran', 'description' => 'Reach character level 35.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 35], 'reward_json' => ['gold' => 1500, 'gems' => 25]],
            ['key' => 'main_reach_level_40', 'name' => 'Veteran Adventurer', 'description' => 'Reach character level 40.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 40], 'reward_json' => ['gold' => 2000, 'gems' => 30]],
            ['key' => 'main_reach_level_45', 'name' => 'Hardened Champion', 'description' => 'Reach character level 45.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 45], 'reward_json' => ['gold' => 2500, 'gems' => 35]],
            ['key' => 'main_reach_level_55', 'name' => 'Renowned Hero', 'description' => 'Reach character level 55.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 55], 'reward_json' => ['gold' => 3500, 'gems' => 50]],
            ['key' => 'main_reach_level_60', 'name' => 'Living Legend', 'description' => 'Reach character level 60.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 60], 'reward_json' => ['gold' => 5000, 'gems' => 75]],
            ['key' => 'main_reach_level_70', 'name' => 'Mythic Contender', 'description' => 'Reach character level 70.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 70], 'reward_json' => ['gold' => 6000, 'gems' => 90]],
            ['key' => 'main_reach_level_85', 'name' => 'Ascendant', 'description' => 'Reach character level 85.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 85], 'reward_json' => ['gold' => 8000, 'gems' => 120]],
            ['key' => 'main_reach_level_100', 'name' => 'Beyond Legend', 'description' => 'Reach character level 100.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 100], 'reward_json' => ['gold' => 12000, 'gems' => 175]],

            // ---- Main, content-breadth (push players through content they might otherwise skip) ----
            ['key' => 'main_craft_25', 'name' => "Forge's Adept", 'description' => 'Collect 25 finished crafts, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'items_crafted', 'target' => 25], 'reward_json' => ['gold' => 700, 'xp' => 500]],
            ['key' => 'main_craft_50', 'name' => "Forge's Master", 'description' => 'Collect 50 finished crafts, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'items_crafted', 'target' => 50], 'reward_json' => ['gold' => 1400, 'xp' => 900]],
            ['key' => 'main_dungeon_10', 'name' => 'Dungeon Conqueror', 'description' => 'Clear 10 dungeons, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'dungeons_cleared', 'target' => 10], 'reward_json' => ['gold' => 900, 'gems' => 15]],
            ['key' => 'main_dungeon_20', 'name' => 'Dungeon Master', 'description' => 'Clear 20 dungeons, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'dungeons_cleared', 'target' => 20], 'reward_json' => ['gold' => 1800, 'gems' => 30]],
            ['key' => 'main_pvp_15', 'name' => 'Blooded Duelist', 'description' => 'Win 15 PvP matches, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'pvp_wins', 'target' => 15], 'reward_json' => ['gold' => 1000, 'gems' => 20]],
            ['key' => 'main_pvp_30', 'name' => 'Arena Legend', 'description' => 'Win 30 PvP matches, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'pvp_wins', 'target' => 30], 'reward_json' => ['gold' => 2200, 'gems' => 40]],
            ['key' => 'main_zones_8', 'name' => 'World Traveler', 'description' => 'Visit 8 different zones, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'zones_visited', 'target' => 8], 'reward_json' => ['gold' => 600, 'xp' => 300]],
            ['key' => 'main_gather_100', 'name' => 'Resourceful', 'description' => 'Gather 100 materials, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'materials_gathered', 'target' => 100], 'reward_json' => ['gold' => 700, 'xp' => 400]],
            ['key' => 'main_gather_250', 'name' => 'Master Gatherer', 'description' => 'Gather 250 materials, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'materials_gathered', 'target' => 250], 'reward_json' => ['gold' => 1500, 'xp' => 800]],
            ['key' => 'main_battles_100', 'name' => 'Hundred Victories', 'description' => 'Win 100 battles, lifetime.', 'type' => 'main', 'goal_json' => ['kind' => 'battles_won', 'target' => 100], 'reward_json' => ['gold' => 1200, 'xp' => 600]],

            // ---- Main, class-specific: tier-2 skill unlock (Lv.20 path) ----
            ['key' => 'main_warrior_cleave', 'name' => 'Master of Warfare', 'description' => 'Unlock Cleave.', 'type' => 'main', 'class_key' => 'warrior', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'cleave'], 'reward_json' => ['gold' => 600, 'xp' => 400]],
            ['key' => 'main_mage_chain_cast', 'name' => 'Master of Sorcery', 'description' => 'Unlock Chain Cast.', 'type' => 'main', 'class_key' => 'mage', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'chain_cast'], 'reward_json' => ['gold' => 600, 'xp' => 400]],
            ['key' => 'main_rogue_shadow_strike', 'name' => 'Master of Shadowcraft', 'description' => 'Unlock Shadow Strike.', 'type' => 'main', 'class_key' => 'rogue', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'shadow_strike'], 'reward_json' => ['gold' => 600, 'xp' => 400]],
            ['key' => 'main_ranger_piercing_shot', 'name' => 'Master of Marksmanship', 'description' => 'Unlock Piercing Shot.', 'type' => 'main', 'class_key' => 'ranger', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'piercing_shot'], 'reward_json' => ['gold' => 600, 'xp' => 400]],

            // ---- Main, class-specific: signature ultimate unlock (Lv.40 path) ----
            ['key' => 'main_warrior_undying', 'name' => 'The Undying', 'description' => 'Unlock Undying, the Warrior signature skill.', 'type' => 'main', 'class_key' => 'warrior', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'undying'], 'reward_json' => ['gold' => 1500, 'gems' => 25]],
            ['key' => 'main_mage_void_nova', 'name' => 'Herald of the Void', 'description' => 'Unlock Void Nova, the Mage signature skill.', 'type' => 'main', 'class_key' => 'mage', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'void_nova'], 'reward_json' => ['gold' => 1500, 'gems' => 25]],
            ['key' => 'main_rogue_thousand_cuts', 'name' => 'Death by a Thousand Cuts', 'description' => 'Unlock Thousand Cuts, the Rogue signature skill.', 'type' => 'main', 'class_key' => 'rogue', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'thousand_cuts'], 'reward_json' => ['gold' => 1500, 'gems' => 25]],
            ['key' => 'main_ranger_rain_of_arrows', 'name' => 'Storm of Arrows', 'description' => 'Unlock Rain of Arrows, the Ranger signature skill.', 'type' => 'main', 'class_key' => 'ranger', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'rain_of_arrows'], 'reward_json' => ['gold' => 1500, 'gems' => 25]],

            // ---- Main, class-specific: additional milestone per class ----
            ['key' => 'main_warrior_gladiator', 'name' => 'Gladiator', 'description' => 'Win 10 PvP matches as a Warrior.', 'type' => 'main', 'class_key' => 'warrior', 'goal_json' => ['kind' => 'pvp_wins', 'target' => 10], 'reward_json' => ['gold' => 800, 'xp' => 400]],
            ['key' => 'main_mage_healing_light', 'name' => 'Light of Restoration', 'description' => 'Unlock Healing Light, the Mage restoration skill.', 'type' => 'main', 'class_key' => 'mage', 'goal_json' => ['kind' => 'skill_unlocked', 'skill_key' => 'healing_light'], 'reward_json' => ['gold' => 500, 'xp' => 350]],
            ['key' => 'main_rogue_infiltrator', 'name' => 'Infiltrator', 'description' => 'Clear 5 dungeons as a Rogue.', 'type' => 'main', 'class_key' => 'rogue', 'goal_json' => ['kind' => 'dungeons_cleared', 'target' => 5], 'reward_json' => ['gold' => 700, 'xp' => 400]],
            ['key' => 'main_ranger_trailblazer', 'name' => 'Trailblazer', 'description' => 'Visit 5 different zones as a Ranger.', 'type' => 'main', 'class_key' => 'ranger', 'goal_json' => ['kind' => 'zones_visited', 'target' => 5], 'reward_json' => ['gold' => 500, 'xp' => 300]],

            // ---- Raid ----
            ['key' => 'raid_ashfang_dragon', 'name' => 'The Ashfang Hunt', 'description' => 'Defeat the Ashfang Dragon world boss.', 'type' => 'raid', 'goal_json' => ['kind' => 'boss_kill', 'monster_key' => 'ashfang_dragon'], 'reward_json' => ['gems' => 100]],
            ['key' => 'raid_void_sovereign', 'name' => 'The Final Sovereign', 'description' => 'Defeat the Void Sovereign world boss.', 'type' => 'raid', 'goal_json' => ['kind' => 'boss_kill', 'monster_key' => 'void_sovereign'], 'reward_json' => ['gems' => 250]],
            ['key' => 'raid_abyss_kraken', 'name' => 'Depths of the Abyss', 'description' => 'Defeat the Abyss Kraken.', 'type' => 'raid', 'goal_json' => ['kind' => 'boss_kill', 'monster_key' => 'abyss_kraken'], 'reward_json' => ['gems' => 80]],
            ['key' => 'raid_ice_golem', 'name' => 'Frostpeak Sentinel', 'description' => 'Defeat the Ice Golem.', 'type' => 'raid', 'goal_json' => ['kind' => 'boss_kill', 'monster_key' => 'ice_golem'], 'reward_json' => ['gems' => 40]],
            ['key' => 'raid_dark_spirit', 'name' => 'Exorcism', 'description' => 'Defeat the Dark Spirit.', 'type' => 'raid', 'goal_json' => ['kind' => 'boss_kill', 'monster_key' => 'dark_spirit'], 'reward_json' => ['gems' => 40]],
        ];

        foreach ($quests as $quest) {
            Quest::updateOrCreate(['key' => $quest['key']], $quest);
        }
    }
}
