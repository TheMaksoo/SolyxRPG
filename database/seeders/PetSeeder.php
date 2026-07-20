<?php

namespace Database\Seeders;

use App\Models\Pet;
use Illuminate\Database\Seeder;

class PetSeeder extends Seeder
{
    public function run(): void
    {
        $pets = [
            ['key' => 'spirit_owl', 'name' => 'Spirit Owl', 'glyph' => '🦉', 'description' => 'A wise owl that accelerates your XP gains.', 'bonus_json' => ['xp_pct' => 15], 'unlock_gems' => null, 'unlock_gold' => 50000],
            ['key' => 'frost_wolf', 'name' => 'Frost Wolf', 'glyph' => '🐺', 'description' => 'A loyal wolf that boosts your attack in battle.', 'bonus_json' => ['atk_pct' => 10], 'unlock_gems' => 150],
            ['key' => 'baby_drake', 'name' => 'Baby Drake', 'glyph' => '🐲', 'description' => 'A young dragon that sharpens your critical strikes.', 'bonus_json' => ['crit_pct' => 8], 'unlock_gems' => 300],
            ['key' => 'mini_golem', 'name' => 'Mini Golem', 'glyph' => '🗿', 'description' => 'A pocket golem that hardens your defenses.', 'bonus_json' => ['def_pct' => 20], 'unlock_gems' => 300],
            ['key' => 'worker_badger', 'name' => 'Worker Badger', 'glyph' => '🦡', 'description' => 'A tireless digger that speeds up every gathering skill.', 'bonus_json' => ['gather_speed_pct' => 12], 'unlock_gems' => 250],
            ['key' => 'forge_sprite', 'name' => 'Forge Sprite', 'glyph' => '🧚', 'description' => 'A nimble sprite that works the forge alongside you, speeding up crafting.', 'bonus_json' => ['craft_speed_pct' => 15], 'unlock_gems' => 300],
        ];

        foreach ($pets as $index => $pet) {
            $pet['sort_order'] = $index;
            Pet::updateOrCreate(['key' => $pet['key']], $pet);
        }
    }
}
