<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        // Spread much earlier/wider than before (previously 1/35/40/45/50/60, with the last three permanently
        // `locked` regardless of level — nobody could ever reach them). Now every zone is reachable purely by
        // level, on roughly the cadence the game already uses elsewhere (10/20/30/50/100).
        $zones = [
            ['key' => 'whispering_meadows', 'name' => 'Whispering Meadows', 'glyph' => '🌾', 'danger' => 'safe', 'min_level' => 1, 'locked' => false, 'sort_order' => 0],
            // min_level matches each zone's weakest non-boss monster (see MonsterSeeder) so a player who
            // just unlocked the zone always has something to fight — it used to sit several levels below
            // the first monster actually available there, leaving a dead stretch with nothing to walk into.
            ['key' => 'dark_forest', 'name' => 'Dark Forest', 'glyph' => '🌲', 'danger' => 'medium', 'min_level' => 15, 'locked' => false, 'sort_order' => 1],
            ['key' => 'frostpeak_caverns', 'name' => 'Frostpeak Caverns', 'glyph' => '🏔', 'danger' => 'high', 'min_level' => 24, 'locked' => false, 'sort_order' => 2],
            ['key' => 'emberpeak_volcano', 'name' => 'Emberpeak Volcano', 'glyph' => '🌋', 'danger' => 'high', 'min_level' => 32, 'locked' => false, 'sort_order' => 3],
            ['key' => 'sunken_abyss', 'name' => 'Sunken Abyss', 'glyph' => '🌊', 'danger' => 'deadly', 'min_level' => 50, 'locked' => false, 'sort_order' => 4],
            // Three bridge zones (75/120/175), closing what used to be 40-49 level dead stretches between
            // the 50/100/150/200 tiers with no new zone, dungeon, or general gear/recipe of any kind.
            ['key' => 'drowned_spire', 'name' => 'The Drowned Spire', 'glyph' => '🗼', 'danger' => 'deadly', 'min_level' => 75, 'locked' => false, 'sort_order' => 5],
            ['key' => 'the_void', 'name' => 'The Void', 'glyph' => '🌌', 'danger' => 'deadly', 'min_level' => 100, 'locked' => false, 'sort_order' => 6],
            ['key' => 'sundered_rift', 'name' => 'The Sundered Rift', 'glyph' => '🌀', 'danger' => 'deadly', 'min_level' => 120, 'locked' => false, 'sort_order' => 7],
            ['key' => 'crimson_wastes', 'name' => 'Crimson Wastes', 'glyph' => '🏜', 'danger' => 'deadly', 'min_level' => 150, 'locked' => false, 'sort_order' => 8],
            ['key' => 'emberfall_ridge', 'name' => 'Emberfall Ridge', 'glyph' => '🔥', 'danger' => 'deadly', 'min_level' => 175, 'locked' => false, 'sort_order' => 9],
            ['key' => 'astral_expanse', 'name' => 'Astral Expanse', 'glyph' => '🌠', 'danger' => 'deadly', 'min_level' => 200, 'locked' => false, 'sort_order' => 10],
        ];

        foreach ($zones as $zone) {
            Zone::updateOrCreate(['key' => $zone['key']], $zone);
        }
    }
}
