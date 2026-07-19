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

        $monsters = [
            // Whispering Meadows starters — fill the Lv.1-34 gap before Dark Forest's Lv.35+ content
            ['key' => 'slime', 'name' => 'Slime', 'glyph' => '🟢', 'hp' => 40, 'atk' => 8, 'gold' => 12, 'xp' => 20, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 1, 'skills_json' => self::normalKit('Acid Splash', 1.5)],
            ['key' => 'field_mouse', 'name' => 'Field Mouse', 'glyph' => '🐭', 'hp' => 30, 'atk' => 6, 'gold' => 8, 'xp' => 15, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 1, 'skills_json' => self::normalKit('Frantic Scurry', 0.6, 2)],
            ['key' => 'wild_boar', 'name' => 'Wild Boar', 'glyph' => '🐗', 'hp' => 90, 'atk' => 16, 'gold' => 25, 'xp' => 45, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 4, 'skills_json' => self::normalKit('Charge', 1.6)],
            ['key' => 'bandit_scout', 'name' => 'Bandit Scout', 'glyph' => '🗡', 'hp' => 180, 'atk' => 28, 'gold' => 60, 'xp' => 110, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 10, 'skills_json' => self::normalKit('Dagger Flurry', 0.55, 3, 4)],
            ['key' => 'giant_spider', 'name' => 'Giant Spider', 'glyph' => '🕷', 'hp' => 300, 'atk' => 42, 'gold' => 110, 'xp' => 200, 'gems' => 0, 'is_boss' => false, 'is_elite' => true, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 18, 'skills_json' => self::eliteKit('Venom Fangs', 1.5, 1, 'Molt', 8)],
            ['key' => 'rogue_knight', 'name' => 'Rogue Knight', 'glyph' => '⚔', 'hp' => 380, 'atk' => 55, 'gold' => 160, 'xp' => 300, 'gems' => 0, 'is_boss' => false, 'is_elite' => true, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 28, 'skills_json' => self::eliteKit('Riposte', 1.6, 1, 'Second Wind', 8)],

            ['key' => 'forest_stalker', 'name' => 'Forest Stalker', 'glyph' => '🦊', 'hp' => 255, 'atk' => 37, 'gold' => 90, 'xp' => 165, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 15, 'skills_json' => self::normalKit('Ambush', 1.6)],
            ['key' => 'shadow_wolf', 'name' => 'Shadow Wolf', 'glyph' => '🐺', 'hp' => 420, 'atk' => 60, 'gold' => 180, 'xp' => 340, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 35, 'skills_json' => self::normalKit('Savage Pounce', 1.6)],
            ['key' => 'dark_spirit', 'name' => 'Dark Spirit', 'glyph' => '👻', 'hp' => 560, 'atk' => 85, 'gold' => 260, 'xp' => 480, 'gems' => 0, 'is_boss' => false, 'is_elite' => true, 'zone_id' => $zoneId('dark_forest'), 'min_level' => 38, 'skills_json' => self::eliteKit('Spectral Barrage', 0.6, 3, 'Drain Essence', 10)],
            ['key' => 'frost_bat', 'name' => 'Frost Bat', 'glyph' => '🦇', 'hp' => 350, 'atk' => 50, 'gold' => 140, 'xp' => 260, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 24, 'skills_json' => self::normalKit('Sonic Screech', 0.6, 2)],
            ['key' => 'stone_golem', 'name' => 'Stone Golem', 'glyph' => '🗿', 'hp' => 900, 'atk' => 110, 'gold' => 420, 'xp' => 720, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 40, 'skills_json' => self::normalKit('Rockfall', 1.7, 1, 4)],
            ['key' => 'ice_golem', 'name' => 'Ice Golem', 'glyph' => '❄', 'hp' => 1100, 'atk' => 130, 'gold' => 480, 'xp' => 820, 'gems' => 0, 'is_boss' => false, 'is_elite' => true, 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 42, 'skills_json' => self::eliteKit('Ice Shard Barrage', 0.6, 3, 'Glacial Mend', 10)],
            ['key' => 'magma_slug', 'name' => 'Magma Slug', 'glyph' => '🌋', 'hp' => 550, 'atk' => 73, 'gold' => 245, 'xp' => 440, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 32, 'skills_json' => self::normalKit('Molten Ooze', 1.5)],
            ['key' => 'fire_imp', 'name' => 'Fire Imp', 'glyph' => '👹', 'hp' => 700, 'atk' => 90, 'gold' => 325, 'xp' => 570, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 38, 'skills_json' => self::normalKit('Fireball', 1.6)],
            ['key' => 'ashfang_dragon', 'name' => 'Ashfang Dragon', 'glyph' => '🐉', 'hp' => 1600, 'atk' => 160, 'gold' => 2000, 'xp' => 3000, 'gems' => 50, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 45, 'skills_json' => self::bossKit('Flame Breath', 1.8, 'Wing Flurry', 3, 'Draconic Regeneration', 12)],
            ['key' => 'deep_eel', 'name' => 'Deep Eel', 'glyph' => '🐍', 'hp' => 1570, 'atk' => 143, 'gold' => 625, 'xp' => 1100, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 50, 'skills_json' => self::normalKit('Shock Coil', 1.6)],
            ['key' => 'abyss_kraken', 'name' => 'Abyss Kraken', 'glyph' => '🦑', 'hp' => 2200, 'atk' => 200, 'gold' => 900, 'xp' => 1500, 'gems' => 0, 'is_boss' => false, 'is_elite' => true, 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 50, 'skills_json' => self::eliteKit('Crushing Grip', 1.7, 1, 'Abyssal Regeneration', 10)],
            ['key' => 'void_wraith', 'name' => 'Void Wraith', 'glyph' => '🌌', 'hp' => 3200, 'atk' => 230, 'gold' => 1400, 'xp' => 2450, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('the_void'), 'min_level' => 100, 'skills_json' => self::normalKit('Entropy Grasp', 1.6)],
            ['key' => 'void_sovereign', 'name' => 'Void Sovereign', 'glyph' => '👁', 'hp' => 5000, 'atk' => 320, 'gold' => 5000, 'xp' => 8000, 'gems' => 200, 'is_boss' => true, 'is_elite' => false, 'zone_id' => $zoneId('the_void'), 'min_level' => 60, 'skills_json' => self::bossKit('Reality Tear', 2.0, 'Chaos Barrage', 3, 'Void Restoration', 15)],
        ];

        foreach ($monsters as $monster) {
            Monster::updateOrCreate(['key' => $monster['key']], $monster);
        }
    }
}
