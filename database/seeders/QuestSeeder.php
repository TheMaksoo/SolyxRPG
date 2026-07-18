<?php

namespace Database\Seeders;

use App\Models\Quest;
use Illuminate\Database\Seeder;

class QuestSeeder extends Seeder
{
    public function run(): void
    {
        $quests = [
            ['key' => 'daily_slay_5', 'name' => 'Slay 5 Monsters', 'description' => 'Defeat any 5 monsters today.', 'type' => 'daily', 'goal_json' => ['kind' => 'battles_won', 'target' => 5], 'reward_json' => ['gold' => 200, 'xp' => 150]],
            ['key' => 'daily_travel', 'name' => 'Explore a Zone', 'description' => 'Travel to any zone today.', 'type' => 'daily', 'goal_json' => ['kind' => 'zones_visited', 'target' => 1], 'reward_json' => ['gold' => 80]],
            ['key' => 'weekly_dungeon_clear', 'name' => 'Clear a Dungeon', 'description' => 'Clear any dungeon this week.', 'type' => 'weekly', 'goal_json' => ['kind' => 'dungeons_cleared', 'target' => 1], 'reward_json' => ['gems' => 25]],
            ['key' => 'main_reach_level_10', 'name' => 'Rising Adventurer', 'description' => 'Reach character level 10.', 'type' => 'main', 'goal_json' => ['kind' => 'level', 'target' => 10], 'reward_json' => ['gold' => 500, 'gems' => 10]],
            ['key' => 'raid_ashfang_dragon', 'name' => 'The Ashfang Hunt', 'description' => 'Defeat the Ashfang Dragon world boss.', 'type' => 'raid', 'goal_json' => ['kind' => 'boss_kill', 'monster_key' => 'ashfang_dragon'], 'reward_json' => ['gems' => 100]],
        ];

        foreach ($quests as $quest) {
            Quest::updateOrCreate(['key' => $quest['key']], $quest);
        }
    }
}
