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

        // One dungeon per zone, each keyed to that zone's actual region boss (MonsterSeeder's is_boss=true
        // row) — region bosses are excluded from normal wandering (BattleController::walk() and
        // AutoBattleService both filter is_boss=false) precisely because the intended way to fight one is
        // through its dungeon, not a random encounter. Before this pass, 3 of the 6 region bosses (Giant
        // Spider, Frost Wyrm, Abyss Kraken) had no dungeon at all and were unreachable content, and
        // Haunted Crypt pointed at Dark Spirit — a regular Dark Forest monster, not its region boss
        // (Rogue Knight).
        $dungeons = [
            ['key' => 'spider_den', 'name' => "Spider's Den", 'glyph' => '🕷', 'difficulty' => 'normal', 'boss_monster_id' => $bossId('giant_spider'), 'min_level' => 14, 'party_size' => 1, 'drops_json' => ['gold' => 600]],
            ['key' => 'haunted_crypt', 'name' => 'Haunted Crypt', 'glyph' => '⚰', 'difficulty' => 'normal', 'boss_monster_id' => $bossId('rogue_knight'), 'min_level' => 23, 'party_size' => 1, 'drops_json' => ['gold' => 1200, 'gems' => 3]],
            ['key' => 'wyrms_hollow', 'name' => "Wyrm's Hollow", 'glyph' => '🐲', 'difficulty' => 'hard', 'boss_monster_id' => $bossId('frost_wyrm'), 'min_level' => 31, 'party_size' => 2, 'drops_json' => ['gold' => 2500, 'gems' => 8]],
            ['key' => 'dragon_lair', 'name' => 'Dragon Lair', 'glyph' => '🐉', 'difficulty' => 'raid', 'boss_monster_id' => $bossId('ashfang_dragon'), 'min_level' => 50, 'party_size' => 4, 'drops_json' => ['gems' => 20]],
            // Three new dungeons, one per bridge zone added in ZoneSeeder (drowned_spire/sundered_rift/
            // emberfall_ridge) — same 'raid' tier as their neighbors, min_level matched to the zone's own
            // min_level (mirrors dragon_lair's own pattern) rather than its boss's min_level, since the
            // boss never wanders anyway and the dungeon is the only way to fight it.
            ['key' => 'drowned_spire_ruins', 'name' => 'The Drowned Spire', 'glyph' => '🗼', 'difficulty' => 'raid', 'boss_monster_id' => $bossId('spire_warden'), 'min_level' => 75, 'party_size' => 4, 'drops_json' => ['gold' => 3000, 'gems' => 25]],
            ['key' => 'krakens_trench', 'name' => "Kraken's Trench", 'glyph' => '🦑', 'difficulty' => 'raid', 'boss_monster_id' => $bossId('abyss_kraken'), 'min_level' => 99, 'party_size' => 4, 'drops_json' => ['gold' => 6000, 'gems' => 30]],
            ['key' => 'sundered_rift_maw', 'name' => 'The Sundered Maw', 'glyph' => '🌀', 'difficulty' => 'raid', 'boss_monster_id' => $bossId('rift_sovereign'), 'min_level' => 120, 'party_size' => 4, 'drops_json' => ['gold' => 7500, 'gems' => 38]],
            ['key' => 'the_void_throne', 'name' => 'The Void Throne', 'glyph' => '👁', 'difficulty' => 'mythic', 'boss_monster_id' => $bossId('void_sovereign'), 'min_level' => 140, 'party_size' => 6, 'drops_json' => ['gems' => 50]],
            ['key' => 'crimson_bastion', 'name' => 'Crimson Bastion', 'glyph' => '💀', 'difficulty' => 'raid', 'boss_monster_id' => $bossId('sandstorm_titan'), 'min_level' => 155, 'party_size' => 4, 'drops_json' => ['gold' => 9000, 'gems' => 45]],
            ['key' => 'emberfall_forge', 'name' => 'The Emberfall Forge', 'glyph' => '🔥', 'difficulty' => 'raid', 'boss_monster_id' => $bossId('ember_sovereign'), 'min_level' => 175, 'party_size' => 4, 'drops_json' => ['gold' => 12000, 'gems' => 60]],
            ['key' => 'astral_sanctum', 'name' => 'Astral Sanctum', 'glyph' => '🌟', 'difficulty' => 'mythic', 'boss_monster_id' => $bossId('astral_emperor'), 'min_level' => 210, 'party_size' => 6, 'drops_json' => ['gems' => 90]],
        ];

        foreach ($dungeons as $dungeon) {
            Dungeon::updateOrCreate(['key' => $dungeon['key']], $dungeon);
        }

        // Superseded by haunted_crypt now pointing at Dark Forest's actual region boss (Rogue Knight)
        // rather than a regular monster (Shadow Wolf) — disabled rather than deleted to keep any
        // historical dungeon-run rows intact.
        Dungeon::where('key', 'wolf_den')->update(['enabled' => false]);
    }
}
