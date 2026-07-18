<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $achievements = [
            ['key' => 'first_blood', 'name' => 'First Blood', 'glyph' => '🩸', 'description' => 'Win your first battle.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 1]],
            ['key' => 'monster_hunter', 'name' => 'Monster Hunter', 'glyph' => '🗡', 'description' => 'Win 50 battles.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 50]],
            ['key' => 'veteran', 'name' => 'Veteran', 'glyph' => '🎖', 'description' => 'Win 300 battles.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 300]],
            ['key' => 'boss_slayer', 'name' => 'Boss Slayer', 'glyph' => '💀', 'description' => 'Defeat your first boss.', 'requirement_json' => ['kind' => 'bosses_slain', 'target' => 1]],
            ['key' => 'dragon_slayer', 'name' => 'Dragon Slayer', 'glyph' => '🐉', 'description' => 'Defeat the Ashfang Dragon.', 'requirement_json' => ['kind' => 'boss_kill', 'monster_key' => 'ashfang_dragon']],
            ['key' => 'rising_star', 'name' => 'Rising Star', 'glyph' => '⭐', 'description' => 'Reach level 10.', 'requirement_json' => ['kind' => 'level', 'target' => 10]],
            ['key' => 'seasoned', 'name' => 'Seasoned Adventurer', 'glyph' => '🏅', 'description' => 'Reach level 30.', 'requirement_json' => ['kind' => 'level', 'target' => 30]],
            ['key' => 'legend', 'name' => 'Legend', 'glyph' => '👑', 'description' => 'Reach level 60.', 'requirement_json' => ['kind' => 'level', 'target' => 60]],
            ['key' => 'wealthy', 'name' => 'Wealthy', 'glyph' => '💰', 'description' => 'Hold 10,000 gold at once.', 'requirement_json' => ['kind' => 'gold', 'target' => 10000]],
        ];

        foreach ($achievements as $a) {
            Achievement::updateOrCreate(['key' => $a['key']], $a);
        }
    }
}
