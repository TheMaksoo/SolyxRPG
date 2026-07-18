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
        ];

        foreach ($flags as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag['key']], $flag);
        }
    }
}
