<?php

namespace Database\Seeders;

use App\Models\Monster;
use App\Models\Zone;
use Illuminate\Database\Seeder;

class MonsterSeeder extends Seeder
{
    /** Basic attack every monster has, always off cooldown — the AI's guaranteed fallback action. */
    private const BASIC = ['key' => 'basic_attack', 'name' => 'Attack', 'type' => 'attack', 'dmg_mult' => 1.0, 'hits' => 1, 'weight' => 65, 'cooldown' => 0];

    /** A trash monster's kit: the basic attack plus one flavored heavy/multi-hit special. No regen — that's reserved for Elite/Boss. */
    private static function normalKit(string $specialName, float $dmgMult, int $hits = 1, int $cooldown = 3): array
    {
        return [self::BASIC, ['key' => 'special', 'name' => $specialName, 'type' => 'attack', 'dmg_mult' => $dmgMult, 'hits' => $hits, 'weight' => 35, 'cooldown' => $cooldown]];
    }

    /** An Elite's kit: basic attack + heavy special + a self-heal it leans on to outlast a longer fight. */
    private static function eliteKit(string $specialName, float $dmgMult, int $hits, string $regenName, int $healPct): array
    {
        return [
            self::BASIC,
            ['key' => 'special', 'name' => $specialName, 'type' => 'attack', 'dmg_mult' => $dmgMult, 'hits' => $hits, 'weight' => 30, 'cooldown' => 3],
            ['key' => 'regen', 'name' => $regenName, 'type' => 'regen', 'heal_pct' => $healPct, 'weight' => 15, 'cooldown' => 4],
        ];
    }

    /** A Boss's full kit: basic + heavy nuke + multi-hit flurry + a bigger self-heal. */
    private static function bossKit(string $heavyName, float $heavyMult, string $flurryName, int $flurryHits, string $regenName, int $healPct): array
    {
        return [
            self::BASIC,
            ['key' => 'heavy', 'name' => $heavyName, 'type' => 'attack', 'dmg_mult' => $heavyMult, 'hits' => 1, 'weight' => 25, 'cooldown' => 3],
            ['key' => 'flurry', 'name' => $flurryName, 'type' => 'attack', 'dmg_mult' => 0.55, 'hits' => $flurryHits, 'weight' => 20, 'cooldown' => 4],
            ['key' => 'regen', 'name' => $regenName, 'type' => 'regen', 'heal_pct' => $healPct, 'weight' => 12, 'cooldown' => 5],
        ];
    }

    public function run(): void
    {
        $zoneId = fn (string $key) => Zone::where('key', $key)->value('id');

        // Every zone now carries exactly 3 regular monsters spaced across [zone min_level, next zone's
        // min_level) plus 1 region boss near the top of that range — previously several zones' monster
        // levels overlapped or ran straight past the next zone's own range (e.g. Dark Forest's own
        // monsters went up to level 38, past Frostpeak Caverns' level-24 start), and most zones had no
        // boss at all. Field Mouse is disabled rather than deleted (keeps any historical battle rows
        // intact) since Whispering Meadows only needs 3 regular + 1 boss.
        $monsters = [
            // Whispering Meadows [1, 15) — boss at 14.
            
            ['key' => 'field_mouse', 'name' => 'Field Mouse', 'glyph' => '🐭', 'hp' => 30, 'atk' => 6, 'gold' => 6, 'xp' => 15, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 1, 'enabled' => false, 'skills_json' => self::normalKit('Frantic Scurry', 0.6, 2)],
            ['key' => 'slime', 'name' => 'Slime', 'glyph' => '🟢', 'hp' => 40, 'atk' => 8, 'gold' => 8, 'xp' => 20, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 1, 'enabled' => true, 'skills_json' => self::normalKit('Acid Splash', 1.5)],
            ['key' => 'wild_boar', 'name' => 'Wild Boar', 'glyph' => '🐗', 'hp' => 110, 'atk' => 18, 'gold' => 27, 'xp' => 68, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 6, 'enabled' => true, 'skills_json' => self::normalKit('Charge', 1.6)],
            ['key' => 'bandit_scout', 'name' => 'Bandit Scout', 'glyph' => '🗡', 'hp' => 190, 'atk' => 28, 'gold' => 46, 'xp' => 120, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 11, 'enabled' => true, 'skills_json' => self::normalKit('Dagger Flurry', 0.55, 3, 4)],
            ['key' => 'giant_spider', 'name' => 'Giant Spider', 'glyph' => '🕷', 'hp' => 450, 'atk' => 50, 'gold' => 293, 'xp' => 900, 'gems' => 4, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 14, 'enabled' => true, 'skills_json' => self::bossKit('Venom Bite', 1.7, 'Leg Swarm', 3, 'Molt', 10)],

            // Dark Forest [15, 24) — boss at 23 (Rogue Knight, reassigned from Whispering Meadows).
            ['key' => 'forest_stalker', 'name' => 'Forest Stalker', 'glyph' => '🦊', 'hp' => 255, 'atk' => 37, 'gold' => 63, 'xp' => 165, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 15, 'enabled' => true, 'skills_json' => self::normalKit('Ambush', 1.6)],
            ['key' => 'shadow_wolf', 'name' => 'Shadow Wolf', 'glyph' => '🐺', 'hp' => 300, 'atk' => 42, 'gold' => 77, 'xp' => 200, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 18, 'enabled' => true, 'skills_json' => self::normalKit('Savage Pounce', 1.6)],
            ['key' => 'dark_spirit', 'name' => 'Dark Spirit', 'glyph' => '👻', 'hp' => 340, 'atk' => 46, 'gold' => 95, 'xp' => 250, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 21, 'enabled' => true, 'skills_json' => self::normalKit('Spectral Barrage', 0.6, 3)],
            ['key' => 'rogue_knight', 'name' => 'Rogue Knight', 'glyph' => '⚔', 'hp' => 650, 'atk' => 75, 'gold' => 503, 'xp' => 1600, 'gems' => 6, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 23, 'enabled' => true, 'skills_json' => self::bossKit('Riposte', 1.8, 'Blade Dance', 3, 'Second Wind', 10)],

            // Frostpeak Caverns [24, 32) — boss at 31 (new: Frost Wyrm).
            ['key' => 'frost_bat', 'name' => 'Frost Bat', 'glyph' => '🦇', 'hp' => 350, 'atk' => 50, 'gold' => 98, 'xp' => 260, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 24, 'enabled' => true, 'skills_json' => self::normalKit('Sonic Screech', 0.6, 2)],
            ['key' => 'stone_golem', 'name' => 'Stone Golem', 'glyph' => '🗿', 'hp' => 430, 'atk' => 59, 'gold' => 124, 'xp' => 328, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 27, 'enabled' => true, 'skills_json' => self::normalKit('Rockfall', 1.7, 1, 4)],
            ['key' => 'ice_golem', 'name' => 'Ice Golem', 'glyph' => '❄', 'hp' => 480, 'atk' => 64, 'gold' => 138, 'xp' => 373, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 29, 'enabled' => true, 'skills_json' => self::normalKit('Ice Shard Barrage', 0.6, 3)],
            ['key' => 'frost_wyrm', 'name' => 'Frost Wyrm', 'glyph' => '🐲', 'hp' => 1050, 'atk' => 112, 'gold' => 750, 'xp' => 2500, 'gems' => 8, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 31, 'enabled' => true, 'skills_json' => self::bossKit('Glacial Breath', 1.8, 'Tail Slam', 2, 'Glacial Mend', 12)],

            // Emberpeak Volcano [32, 50) — boss at 49 (Ashfang Dragon, relevel from 45).
            ['key' => 'magma_slug', 'name' => 'Magma Slug', 'glyph' => '🌋', 'hp' => 550, 'atk' => 73, 'gold' => 159, 'xp' => 440, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 32, 'enabled' => true, 'skills_json' => self::normalKit('Molten Ooze', 1.5)],
            ['key' => 'fire_imp', 'name' => 'Fire Imp', 'glyph' => '👹', 'hp' => 720, 'atk' => 92, 'gold' => 203, 'xp' => 600, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 38, 'enabled' => true, 'skills_json' => self::normalKit('Fireball', 1.6)],
            ['key' => 'cinder_hound', 'name' => 'Cinder Hound', 'glyph' => '🔥', 'hp' => 950, 'atk' => 115, 'gold' => 256, 'xp' => 780, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 44, 'enabled' => true, 'skills_json' => self::normalKit('Cinder Pounce', 1.6)],
            ['key' => 'ashfang_dragon', 'name' => 'Ashfang Dragon', 'glyph' => '🐉', 'hp' => 1700, 'atk' => 168, 'gold' => 1080, 'xp' => 3000, 'gems' => 10, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 49, 'enabled' => true, 'skills_json' => self::bossKit('Flame Breath', 1.8, 'Wing Flurry', 3, 'Draconic Regeneration', 12)],

            // Sunken Abyss [50, 100) — boss at 99 (Abyss Kraken, promoted from Elite).
            ['key' => 'deep_eel', 'name' => 'Deep Eel', 'glyph' => '🐍', 'hp' => 1570, 'atk' => 143, 'gold' => 358, 'xp' => 1100, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 50, 'enabled' => true, 'skills_json' => self::normalKit('Shock Coil', 1.6)],
            ['key' => 'tide_serpent', 'name' => 'Tide Serpent', 'glyph' => '🌊', 'hp' => 2100, 'atk' => 175, 'gold' => 461, 'xp' => 1450, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 66, 'enabled' => true, 'skills_json' => self::normalKit('Riptide Coil', 1.6)],
            ['key' => 'drowned_wretch', 'name' => 'Drowned Wretch', 'glyph' => '🧟', 'hp' => 2700, 'atk' => 205, 'gold' => 597, 'xp' => 1900, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 83, 'enabled' => true, 'skills_json' => self::normalKit('Waterlogged Grasp', 0.6, 2)],
            ['key' => 'abyss_kraken', 'name' => 'Abyss Kraken', 'glyph' => '🦑', 'hp' => 4200, 'atk' => 280, 'gold' => 1710, 'xp' => 5600, 'gems' => 15, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 99, 'enabled' => true, 'skills_json' => self::bossKit('Crushing Grip', 1.8, 'Tentacle Barrage', 4, 'Abyssal Regeneration', 12)],

            // The Void [100, 150] — the current top of designed content; boss at 148 (Void Sovereign,
            // relevel from a stray 60 that put it below the zone's own 100 gate — unreachable as designed).
            ['key' => 'void_wraith', 'name' => 'Void Wraith', 'glyph' => '🌌', 'hp' => 3200, 'atk' => 230, 'gold' => 765, 'xp' => 2450, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('the_void'), 'min_level' => 100, 'enabled' => true, 'skills_json' => self::normalKit('Entropy Grasp', 1.6)],
            ['key' => 'nether_horror', 'name' => 'Nether Horror', 'glyph' => '🕳', 'hp' => 3900, 'atk' => 265, 'gold' => 923, 'xp' => 3000, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('the_void'), 'min_level' => 117, 'enabled' => true, 'skills_json' => self::normalKit('Nether Rend', 1.6)],
            ['key' => 'void_stalker', 'name' => 'Void Stalker', 'glyph' => '🌑', 'hp' => 4500, 'atk' => 295, 'gold' => 1080, 'xp' => 3550, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('the_void'), 'min_level' => 133, 'enabled' => true, 'skills_json' => self::normalKit('Phase Rend', 0.6, 2)],
            ['key' => 'void_sovereign', 'name' => 'Void Sovereign', 'glyph' => '👁', 'hp' => 5500, 'atk' => 340, 'gold' => 2655, 'xp' => 8000, 'gems' => 20, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('the_void'), 'min_level' => 148, 'enabled' => true, 'skills_json' => self::bossKit('Reality Tear', 2.0, 'Chaos Barrage', 3, 'Void Restoration', 15)],
        ];

        foreach ($monsters as $monster) {
            Monster::updateOrCreate(['key' => $monster['key']], $monster);
        }
    }
}
