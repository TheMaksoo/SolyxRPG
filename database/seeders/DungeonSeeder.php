<?php

namespace Database\Seeders;

use App\Models\Dungeon;
use App\Models\Monster;
use Illuminate\Database\Seeder;

class DungeonSeeder extends Seeder
{
    public function run(): void
    {
        $bossId = fn (string $key) => Monster::where('key', $key)->value('id');

        // Spread much earlier/wider than before (previously 35/40/45/60, all clustered at the high end with
        // nothing reachable before level 35) — now ramps from early-game up to the true level-100 endgame.
        $dungeons = [
            ['key' => 'wolf_den', 'name' => 'Wolf Den', 'glyph' => '🐺', 'difficulty' => 'normal', 'boss_monster_id' => $bossId('shadow_wolf'), 'min_level' => 15, 'party_size' => 1, 'drops_json' => ['gold' => 800]],
            ['key' => 'haunted_crypt', 'name' => 'Haunted Crypt', 'glyph' => '⚰', 'difficulty' => 'hard', 'boss_monster_id' => $bossId('dark_spirit'), 'min_level' => 30, 'party_size' => 1, 'drops_json' => ['gold' => 2000, 'gems' => 5]],
            ['key' => 'dragon_lair', 'name' => 'Dragon Lair', 'glyph' => '🐉', 'difficulty' => 'raid', 'boss_monster_id' => $bossId('ashfang_dragon'), 'min_level' => 55, 'party_size' => 4, 'drops_json' => ['gems' => 20]],
            ['key' => 'the_void_throne', 'name' => 'The Void Throne', 'glyph' => '👁', 'difficulty' => 'mythic', 'boss_monster_id' => $bossId('void_sovereign'), 'min_level' => 90, 'party_size' => 6, 'drops_json' => ['gems' => 50]],
        ];

        foreach ($dungeons as $dungeon) {
            Dungeon::updateOrCreate(['key' => $dungeon['key']], $dungeon);
        }
    }
}
