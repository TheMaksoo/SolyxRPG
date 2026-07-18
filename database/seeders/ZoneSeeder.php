<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['key' => 'whispering_meadows', 'name' => 'Whispering Meadows', 'glyph' => '🌾', 'danger' => 'safe', 'min_level' => 1, 'locked' => false, 'sort_order' => 0],
            ['key' => 'dark_forest', 'name' => 'Dark Forest', 'glyph' => '🌲', 'danger' => 'medium', 'min_level' => 35, 'locked' => false, 'sort_order' => 1],
            ['key' => 'frostpeak_caverns', 'name' => 'Frostpeak Caverns', 'glyph' => '🏔', 'danger' => 'high', 'min_level' => 40, 'locked' => false, 'sort_order' => 2],
            ['key' => 'emberpeak_volcano', 'name' => 'Emberpeak Volcano', 'glyph' => '🌋', 'danger' => 'high', 'min_level' => 45, 'locked' => true, 'sort_order' => 3],
            ['key' => 'sunken_abyss', 'name' => 'Sunken Abyss', 'glyph' => '🌊', 'danger' => 'deadly', 'min_level' => 50, 'locked' => true, 'sort_order' => 4],
            ['key' => 'the_void', 'name' => 'The Void', 'glyph' => '🌌', 'danger' => 'deadly', 'min_level' => 60, 'locked' => true, 'sort_order' => 5],
        ];

        foreach ($zones as $zone) {
            Zone::updateOrCreate(['key' => $zone['key']], $zone);
        }
    }
}
