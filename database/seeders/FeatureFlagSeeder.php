<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        $flags = [
            ['key' => 'global_tester_mode', 'name' => 'Global Tester Mode', 'enabled' => false, 'tester_only' => false],
            ['key' => 'guilds', 'name' => 'Guilds', 'enabled' => true, 'tester_only' => false],
            ['key' => 'battle_pass', 'name' => 'Battle Pass', 'enabled' => true, 'tester_only' => false],
            ['key' => 'gem_store', 'name' => 'Gem Store', 'enabled' => true, 'tester_only' => false],
            ['key' => 'dungeons', 'name' => 'Dungeons', 'enabled' => true, 'tester_only' => false],
            ['key' => 'crafting', 'name' => 'Crafting', 'enabled' => true, 'tester_only' => false],
            ['key' => 'vip', 'name' => 'VIP', 'enabled' => true, 'tester_only' => false],
            ['key' => 'cosmetics', 'name' => 'Cosmetics (Customize)', 'enabled' => true, 'tester_only' => false],
            ['key' => 'shop', 'name' => 'Shop', 'enabled' => true, 'tester_only' => false],
            ['key' => 'skills', 'name' => 'Skills', 'enabled' => true, 'tester_only' => false],
            ['key' => 'trade_skills', 'name' => 'Gathering', 'enabled' => true, 'tester_only' => false],
            ['key' => 'pets', 'name' => 'Companions', 'enabled' => true, 'tester_only' => false],
            ['key' => 'pvp', 'name' => 'PvP Arena', 'enabled' => true, 'tester_only' => false],
            ['key' => 'party', 'name' => 'Party', 'enabled' => true, 'tester_only' => false],
            ['key' => 'friends', 'name' => 'Friends', 'enabled' => true, 'tester_only' => false],
            ['key' => 'leaderboard', 'name' => 'Leaderboard', 'enabled' => true, 'tester_only' => false],
            ['key' => 'daily', 'name' => 'Daily Rewards', 'enabled' => true, 'tester_only' => false],
            ['key' => 'battle', 'name' => 'Battle (starting new fights)', 'enabled' => true, 'tester_only' => false],
            ['key' => 'quests', 'name' => 'Quests', 'enabled' => true, 'tester_only' => false],
            ['key' => 'world_map', 'name' => 'World Map', 'enabled' => true, 'tester_only' => false],
            ['key' => 'inventory', 'name' => 'Inventory', 'enabled' => true, 'tester_only' => false],
        ];

        foreach ($flags as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag['key']], $flag);
        }
    }
}
