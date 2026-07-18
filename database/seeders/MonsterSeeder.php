<?php

namespace Database\Seeders;

use App\Models\Monster;
use App\Models\Zone;
use Illuminate\Database\Seeder;

class MonsterSeeder extends Seeder
{
    public function run(): void
    {
        $zoneId = fn (string $key) => Zone::where('key', $key)->value('id');

        $monsters = [
            ['key' => 'shadow_wolf', 'name' => 'Shadow Wolf', 'glyph' => '🐺', 'hp' => 420, 'atk' => 60, 'gold' => 180, 'xp' => 340, 'gems' => 0, 'is_boss' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 35],
            ['key' => 'dark_spirit', 'name' => 'Dark Spirit', 'glyph' => '👻', 'hp' => 560, 'atk' => 85, 'gold' => 260, 'xp' => 480, 'gems' => 0, 'is_boss' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 38],
            ['key' => 'stone_golem', 'name' => 'Stone Golem', 'glyph' => '🗿', 'hp' => 900, 'atk' => 110, 'gold' => 420, 'xp' => 720, 'gems' => 0, 'is_boss' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 40],
            ['key' => 'ice_golem', 'name' => 'Ice Golem', 'glyph' => '❄', 'hp' => 1100, 'atk' => 130, 'gold' => 480, 'xp' => 820, 'gems' => 0, 'is_boss' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 42],
            ['key' => 'ashfang_dragon', 'name' => 'Ashfang Dragon', 'glyph' => '🐉', 'hp' => 1600, 'atk' => 160, 'gold' => 2000, 'xp' => 3000, 'gems' => 50, 'is_boss' => true, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 45],
            ['key' => 'abyss_kraken', 'name' => 'Abyss Kraken', 'glyph' => '🦑', 'hp' => 2200, 'atk' => 200, 'gold' => 900, 'xp' => 1500, 'gems' => 0, 'is_boss' => false, 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 50],
            ['key' => 'void_sovereign', 'name' => 'Void Sovereign', 'glyph' => '👁', 'hp' => 5000, 'atk' => 320, 'gold' => 5000, 'xp' => 8000, 'gems' => 200, 'is_boss' => true, 'zone_id' => $zoneId('the_void'), 'min_level' => 60],
        ];

        foreach ($monsters as $monster) {
            Monster::updateOrCreate(['key' => $monster['key']], $monster);
        }
    }
}
