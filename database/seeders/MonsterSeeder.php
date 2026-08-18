<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Monster;
use App\Models\Zone;
use Illuminate\Database\Seeder;

class MonsterSeeder extends Seeder
{
    /** [item_key, regular-monster chance_pct, boss chance_pct] per zone — the material a zone's monsters
     * drop, roughly matching that zone's danger-tier material rarity in CrateService::ZONE_MATERIAL_TABLE
     * (safe/medium -> common, high -> epic, deadly -> legendary/mythic), so a monster kill and a crate
     * from the same zone feel like the same loot pool. Boss chance is deliberately much higher than a
     * regular monster's — a boss fight is rare and deliberate, so its drop should feel like a real reward. */
    private const ZONE_LOOT = [
        'whispering_meadows' => ['herb', 18, 55],
        'dark_forest' => ['wood', 18, 55],
        'frostpeak_caverns' => ['silver_ore', 15, null], // boss drops the Frost Wyrm Scale trophy instead, see below
        'emberpeak_volcano' => ['ironwood', 15, null], // boss drops the Ashfang Dragon Scale trophy instead, see below
        'sunken_abyss' => ['gold_ore', 12, 55],
        'drowned_spire' => ['elderwood', 12, 55],
        'the_void' => ['mythril_ore', 10, 55],
        'sundered_rift' => ['moonwood', 10, 55],
        'crimson_wastes' => ['sunroot', 10, 55],
        'emberfall_ridge' => ['mythril_bar', 10, 55],
        'astral_expanse' => ['phoenix_bloom', 10, 55],
    ];

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
        $itemId = Item::pluck('id', 'key');
        // A regular monster's (or generic boss's) zone-tier material drop — see ZONE_LOOT above.
        $lootFor = function (string $zoneKey, bool $isBoss) use ($itemId) {
            [$materialKey, $regularPct, $bossPct] = self::ZONE_LOOT[$zoneKey];
            if ($isBoss) {
                return $bossPct === null ? [] : [['item_id' => $itemId[$materialKey], 'chance_pct' => $bossPct]];
            }

            return [['item_id' => $itemId[$materialKey], 'chance_pct' => $regularPct]];
        };
        // A named boss trophy (Frost Wyrm Scale / Ashfang Dragon Scale) instead of the zone's generic
        // material — these two feed the Legendary/Mythic tier gear recipes (see RecipeSeeder).
        $trophyFor = fn (string $key, int $chancePct) => [['item_id' => $itemId[$key], 'chance_pct' => $chancePct]];

        // Every zone now carries exactly 3 regular monsters spaced across [zone min_level, next zone's
        // min_level) plus 1 region boss near the top of that range — previously several zones' monster
        // levels overlapped or ran straight past the next zone's own range (e.g. Dark Forest's own
        // monsters went up to level 38, past Frostpeak Caverns' level-24 start), and most zones had no
        // boss at all. Field Mouse is disabled rather than deleted (keeps any historical battle rows
        // intact) since Whispering Meadows only needs 3 regular + 1 boss.
        $monsters = [
            // Tutorial-only roster — never returned by the normal zone-based walk() query (see
            // BattleController::walk()'s is_tutorial branch), only fought by a character below level 3.
            // Startled Hare and Slime double as Whispering Meadows' own early monsters thematically, but
            // as tutorial rows they're a separate, deliberately gentle stat line (flat xp 10, not the
            // zone's xp 15/20) so the tutorial's leveling pacing stays exact: 5 fights to hit
            // xp_level_1_to_2 (50), 10 more to hit xp_level_2_to_3 (100) — see Character::buildXpTable().
            ['key' => 'startled_hare', 'name' => 'Startled Hare', 'glyph' => '🐇', 'hp' => 36, 'atk' => 7, 'gold' => 7, 'xp' => 10, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => null, 'is_tutorial' => true, 'min_level' => 1, 'enabled' => true, 'skills_json' => self::normalKit('Startled Kick', 1.5)],
            ['key' => 'slime', 'name' => 'Slime', 'glyph' => '🟢', 'sprite' => 'slime', 'hp' => 34, 'atk' => 7, 'gold' => 7, 'xp' => 10, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'mender', 'zone_id' => null, 'is_tutorial' => true, 'min_level' => 1, 'enabled' => true, 'skills_json' => self::normalKit('Acid Splash', 1.5)],

            // Whispering Meadows [1, 15) — boss at 14. Back down to 3 regular + 1 boss now that Startled
            // Hare and Slime moved to tutorial duty above — Meadow Rat and Brook Sprite still cover
            // level 1 alongside Wild Boar (now carrying the Boar sprite art, cosmetic-only — same stats).
            ['key' => 'field_mouse', 'name' => 'Field Mouse', 'glyph' => '🐭', 'hp' => 30, 'atk' => 6, 'gold' => 6, 'xp' => 15, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 1, 'enabled' => false, 'skills_json' => self::normalKit('Frantic Scurry', 0.6, 2), 'loot_table_json' => $lootFor('whispering_meadows', false)],
            ['key' => 'meadow_rat', 'name' => 'Meadow Rat', 'glyph' => '🐀', 'hp' => 32, 'atk' => 6, 'gold' => 6, 'xp' => 15, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'brute', 'zone_id' => $zoneId('whispering_meadows'), 'is_tutorial' => false, 'min_level' => 1, 'enabled' => true, 'skills_json' => self::normalKit('Nibble', 1.4), 'loot_table_json' => $lootFor('whispering_meadows', false)],
            ['key' => 'brook_sprite', 'name' => 'Brook Sprite', 'glyph' => '💧', 'hp' => 30, 'atk' => 9, 'gold' => 8, 'xp' => 15, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('whispering_meadows'), 'is_tutorial' => false, 'min_level' => 1, 'enabled' => true, 'skills_json' => self::normalKit('Ripple', 1.3), 'loot_table_json' => $lootFor('whispering_meadows', false)],
            ['key' => 'wild_boar', 'name' => 'Wild Boar', 'glyph' => '🐗', 'sprite' => 'boar', 'hp' => 110, 'atk' => 18, 'gold' => 27, 'xp' => 68, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 6, 'enabled' => true, 'skills_json' => self::normalKit('Charge', 1.6), 'loot_table_json' => $lootFor('whispering_meadows', false)],
            ['key' => 'bandit_scout', 'name' => 'Bandit Scout', 'glyph' => '🗡', 'hp' => 190, 'atk' => 28, 'gold' => 46, 'xp' => 120, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'brute', 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 11, 'enabled' => true, 'skills_json' => self::normalKit('Dagger Flurry', 0.55, 3, 4), 'loot_table_json' => $lootFor('whispering_meadows', false)],
            ['key' => 'giant_spider', 'name' => 'Giant Spider', 'glyph' => '🕷', 'hp' => 450, 'atk' => 50, 'gold' => 293, 'xp' => 900, 'gems' => 4, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('whispering_meadows'), 'min_level' => 14, 'enabled' => true, 'skills_json' => self::bossKit('Venom Bite', 1.7, 'Leg Swarm', 3, 'Molt', 10), 'loot_table_json' => $lootFor('whispering_meadows', true)],

            // Dark Forest [15, 24) — boss at 23 (Rogue Knight, reassigned from Whispering Meadows).
            ['key' => 'forest_stalker', 'name' => 'Forest Stalker', 'glyph' => '🦊', 'hp' => 255, 'atk' => 37, 'gold' => 63, 'xp' => 165, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('dark_forest'), 'min_level' => 15, 'enabled' => true, 'skills_json' => self::normalKit('Ambush', 1.6), 'loot_table_json' => $lootFor('dark_forest', false)],
            ['key' => 'shadow_wolf', 'name' => 'Shadow Wolf', 'glyph' => '🐺', 'hp' => 300, 'atk' => 42, 'gold' => 77, 'xp' => 200, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'brute', 'zone_id' => $zoneId('dark_forest'), 'min_level' => 18, 'enabled' => true, 'skills_json' => self::normalKit('Savage Pounce', 1.6), 'loot_table_json' => $lootFor('dark_forest', false)],
            ['key' => 'dark_spirit', 'name' => 'Dark Spirit', 'glyph' => '👻', 'hp' => 340, 'atk' => 46, 'gold' => 95, 'xp' => 250, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'buffer', 'zone_id' => $zoneId('dark_forest'), 'min_level' => 21, 'enabled' => true, 'skills_json' => self::normalKit('Spectral Barrage', 0.6, 3), 'loot_table_json' => $lootFor('dark_forest', false)],
            ['key' => 'rogue_knight', 'name' => 'Rogue Knight', 'glyph' => '⚔', 'hp' => 650, 'atk' => 75, 'gold' => 503, 'xp' => 1600, 'gems' => 6, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('dark_forest'), 'min_level' => 23, 'enabled' => true, 'skills_json' => self::bossKit('Riposte', 1.8, 'Blade Dance', 3, 'Second Wind', 10), 'loot_table_json' => $lootFor('dark_forest', true)],

            // Frostpeak Caverns [24, 32) — boss at 31 (new: Frost Wyrm).
            ['key' => 'frost_bat', 'name' => 'Frost Bat', 'glyph' => '🦇', 'hp' => 350, 'atk' => 50, 'gold' => 98, 'xp' => 260, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 24, 'enabled' => true, 'skills_json' => self::normalKit('Sonic Screech', 0.6, 2), 'loot_table_json' => $lootFor('frostpeak_caverns', false)],
            ['key' => 'stone_golem', 'name' => 'Stone Golem', 'glyph' => '🗿', 'hp' => 430, 'atk' => 59, 'gold' => 124, 'xp' => 328, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'tank', 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 27, 'enabled' => true, 'skills_json' => self::normalKit('Rockfall', 1.7, 1, 4), 'loot_table_json' => $lootFor('frostpeak_caverns', false)],
            ['key' => 'ice_golem', 'name' => 'Ice Golem', 'glyph' => '❄', 'hp' => 480, 'atk' => 64, 'gold' => 138, 'xp' => 373, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 29, 'enabled' => true, 'skills_json' => self::normalKit('Ice Shard Barrage', 0.6, 3), 'loot_table_json' => $lootFor('frostpeak_caverns', false)],
            ['key' => 'frost_wyrm', 'name' => 'Frost Wyrm', 'glyph' => '🐲', 'hp' => 1050, 'atk' => 112, 'gold' => 750, 'xp' => 2500, 'gems' => 8, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('frostpeak_caverns'), 'min_level' => 31, 'enabled' => true, 'skills_json' => self::bossKit('Glacial Breath', 1.8, 'Tail Slam', 2, 'Glacial Mend', 12), 'loot_table_json' => $trophyFor('frost_wyrm_scale', 65)],

            // Emberpeak Volcano [32, 50) — boss at 49 (Ashfang Dragon, relevel from 45).
            ['key' => 'magma_slug', 'name' => 'Magma Slug', 'glyph' => '🌋', 'hp' => 550, 'atk' => 73, 'gold' => 159, 'xp' => 440, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'mender', 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 32, 'enabled' => true, 'skills_json' => self::normalKit('Molten Ooze', 1.5), 'loot_table_json' => $lootFor('emberpeak_volcano', false)],
            ['key' => 'fire_imp', 'name' => 'Fire Imp', 'glyph' => '👹', 'hp' => 720, 'atk' => 92, 'gold' => 203, 'xp' => 600, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 38, 'enabled' => true, 'skills_json' => self::normalKit('Fireball', 1.6), 'loot_table_json' => $lootFor('emberpeak_volcano', false)],
            ['key' => 'cinder_hound', 'name' => 'Cinder Hound', 'glyph' => '🔥', 'hp' => 950, 'atk' => 115, 'gold' => 256, 'xp' => 780, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 44, 'enabled' => true, 'skills_json' => self::normalKit('Cinder Pounce', 1.6), 'loot_table_json' => $lootFor('emberpeak_volcano', false)],
            ['key' => 'ashfang_dragon', 'name' => 'Ashfang Dragon', 'glyph' => '🐉', 'hp' => 1700, 'atk' => 168, 'gold' => 1080, 'xp' => 3000, 'gems' => 10, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('emberpeak_volcano'), 'min_level' => 49, 'enabled' => true, 'skills_json' => self::bossKit('Flame Breath', 1.8, 'Wing Flurry', 3, 'Draconic Regeneration', 12), 'loot_table_json' => $trophyFor('ashfang_dragon_scale', 65)],

            // Sunken Abyss [50, 100) — boss at 99 (Abyss Kraken, promoted from Elite).
            ['key' => 'deep_eel', 'name' => 'Deep Eel', 'glyph' => '🐍', 'hp' => 1570, 'atk' => 143, 'gold' => 358, 'xp' => 1100, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 50, 'enabled' => true, 'skills_json' => self::normalKit('Shock Coil', 1.6), 'loot_table_json' => $lootFor('sunken_abyss', false)],
            ['key' => 'tide_serpent', 'name' => 'Tide Serpent', 'glyph' => '🌊', 'hp' => 2100, 'atk' => 175, 'gold' => 461, 'xp' => 1450, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 66, 'enabled' => true, 'skills_json' => self::normalKit('Riptide Coil', 1.6), 'loot_table_json' => $lootFor('sunken_abyss', false)],
            ['key' => 'drowned_wretch', 'name' => 'Drowned Wretch', 'glyph' => '🧟', 'hp' => 2700, 'atk' => 205, 'gold' => 597, 'xp' => 1900, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'brute', 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 83, 'enabled' => true, 'skills_json' => self::normalKit('Waterlogged Grasp', 0.6, 2), 'loot_table_json' => $lootFor('sunken_abyss', false)],
            ['key' => 'abyss_kraken', 'name' => 'Abyss Kraken', 'glyph' => '🦑', 'hp' => 4200, 'atk' => 280, 'gold' => 1710, 'xp' => 5600, 'gems' => 15, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('sunken_abyss'), 'min_level' => 99, 'enabled' => true, 'skills_json' => self::bossKit('Crushing Grip', 1.8, 'Tentacle Barrage', 4, 'Abyssal Regeneration', 12), 'loot_table_json' => $lootFor('sunken_abyss', true)],

            // The Drowned Spire [75, 100) — a bridge zone between Sunken Abyss (50) and The Void (100),
            // closing what used to be a 49-level gap with no new zone/dungeon at all.
            ['key' => 'coral_revenant', 'name' => 'Coral Revenant', 'glyph' => '🪸', 'hp' => 2200, 'atk' => 165, 'gold' => 520, 'xp' => 1650, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('drowned_spire'), 'min_level' => 75, 'enabled' => true, 'skills_json' => self::normalKit('Coral Lash', 1.6), 'loot_table_json' => $lootFor('drowned_spire', false)],
            ['key' => 'brinewraith', 'name' => 'Brinewraith', 'glyph' => '🫧', 'hp' => 2650, 'atk' => 195, 'gold' => 640, 'xp' => 2000, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('drowned_spire'), 'min_level' => 85, 'enabled' => true, 'skills_json' => self::normalKit('Brine Curse', 1.6), 'loot_table_json' => $lootFor('drowned_spire', false)],
            ['key' => 'abyssal_horror', 'name' => 'Abyssal Horror', 'glyph' => '🐙', 'hp' => 3000, 'atk' => 220, 'gold' => 720, 'xp' => 2300, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'brute', 'zone_id' => $zoneId('drowned_spire'), 'min_level' => 92, 'enabled' => true, 'skills_json' => self::normalKit('Crushing Undertow', 0.6, 2), 'loot_table_json' => $lootFor('drowned_spire', false)],
            ['key' => 'spire_warden', 'name' => 'Spire Warden', 'glyph' => '🗼', 'hp' => 3900, 'atk' => 255, 'gold' => 1250, 'xp' => 4200, 'gems' => 17, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('drowned_spire'), 'min_level' => 98, 'enabled' => true, 'skills_json' => self::bossKit('Tidal Collapse', 1.8, 'Coral Barrage', 3, 'Abyssal Mending', 12), 'loot_table_json' => $lootFor('drowned_spire', true)],

            // The Void [100, 150) — boss at 148 (Void Sovereign, relevel from a stray 60 that put it below
            // the zone's own 100 gate — unreachable as designed).
            ['key' => 'void_wraith', 'name' => 'Void Wraith', 'glyph' => '🌌', 'hp' => 3200, 'atk' => 230, 'gold' => 765, 'xp' => 2450, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('the_void'), 'min_level' => 100, 'enabled' => true, 'skills_json' => self::normalKit('Entropy Grasp', 1.6), 'loot_table_json' => $lootFor('the_void', false)],
            ['key' => 'nether_horror', 'name' => 'Nether Horror', 'glyph' => '🕳', 'hp' => 3900, 'atk' => 265, 'gold' => 923, 'xp' => 3000, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'brute', 'zone_id' => $zoneId('the_void'), 'min_level' => 117, 'enabled' => true, 'skills_json' => self::normalKit('Nether Rend', 1.6), 'loot_table_json' => $lootFor('the_void', false)],
            ['key' => 'void_stalker', 'name' => 'Void Stalker', 'glyph' => '🌑', 'hp' => 4500, 'atk' => 295, 'gold' => 1080, 'xp' => 3550, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('the_void'), 'min_level' => 133, 'enabled' => true, 'skills_json' => self::normalKit('Phase Rend', 0.6, 2), 'loot_table_json' => $lootFor('the_void', false)],
            ['key' => 'void_sovereign', 'name' => 'Void Sovereign', 'glyph' => '👁', 'hp' => 5500, 'atk' => 340, 'gold' => 2655, 'xp' => 8000, 'gems' => 20, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('the_void'), 'min_level' => 148, 'enabled' => true, 'skills_json' => self::bossKit('Reality Tear', 2.0, 'Chaos Barrage', 3, 'Void Restoration', 15), 'loot_table_json' => $lootFor('the_void', true)],

            // The Sundered Rift [120, 150) — a bridge zone between The Void (100) and Crimson Wastes
            // (150), closing what used to be a 40-level gap with no new zone/dungeon at all.
            ['key' => 'rift_stalker', 'name' => 'Rift Stalker', 'glyph' => '🌀', 'hp' => 3650, 'atk' => 245, 'gold' => 900, 'xp' => 2900, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('sundered_rift'), 'min_level' => 120, 'enabled' => true, 'skills_json' => self::normalKit('Rift Slash', 1.6), 'loot_table_json' => $lootFor('sundered_rift', false)],
            ['key' => 'chaos_wisp', 'name' => 'Chaos Wisp', 'glyph' => '⚡', 'hp' => 4000, 'atk' => 265, 'gold' => 1050, 'xp' => 3400, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('sundered_rift'), 'min_level' => 132, 'enabled' => true, 'skills_json' => self::normalKit('Chaos Bolt', 1.6), 'loot_table_json' => $lootFor('sundered_rift', false)],
            ['key' => 'fracture_beast', 'name' => 'Fracture Beast', 'glyph' => '🪨', 'hp' => 4600, 'atk' => 285, 'gold' => 1250, 'xp' => 4000, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'tank', 'zone_id' => $zoneId('sundered_rift'), 'min_level' => 143, 'enabled' => true, 'skills_json' => self::normalKit('Seismic Slam', 1.7, 1, 4), 'loot_table_json' => $lootFor('sundered_rift', false)],
            ['key' => 'rift_sovereign', 'name' => 'Rift Sovereign', 'glyph' => '💫', 'hp' => 6300, 'atk' => 375, 'gold' => 3350, 'xp' => 10500, 'gems' => 22, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('sundered_rift'), 'min_level' => 149, 'enabled' => true, 'skills_json' => self::bossKit('Reality Sunder', 1.9, 'Fracture Storm', 3, 'Rift Restoration', 14), 'loot_table_json' => $lootFor('sundered_rift', true)],

            // Crimson Wastes [150, 200) — boss at 198 (Sandstorm Titan).
            ['key' => 'sand_reaver', 'name' => 'Sand Reaver', 'glyph' => '🦂', 'hp' => 4200, 'atk' => 260, 'gold' => 1900, 'xp' => 6200, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('crimson_wastes'), 'min_level' => 150, 'enabled' => true, 'skills_json' => self::normalKit('Venomous Sting', 1.6), 'loot_table_json' => $lootFor('crimson_wastes', false)],
            ['key' => 'dust_wraith', 'name' => 'Dust Wraith', 'glyph' => '🌪', 'hp' => 5100, 'atk' => 300, 'gold' => 2300, 'xp' => 7600, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'buffer', 'zone_id' => $zoneId('crimson_wastes'), 'min_level' => 167, 'enabled' => true, 'skills_json' => self::normalKit('Sand Vortex', 0.6, 3), 'loot_table_json' => $lootFor('crimson_wastes', false)],
            ['key' => 'bone_colossus', 'name' => 'Bone Colossus', 'glyph' => '🦴', 'hp' => 6000, 'atk' => 340, 'gold' => 2750, 'xp' => 9200, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'tank', 'zone_id' => $zoneId('crimson_wastes'), 'min_level' => 183, 'enabled' => true, 'skills_json' => self::normalKit('Bone Crush', 1.7, 1, 4), 'loot_table_json' => $lootFor('crimson_wastes', false)],
            ['key' => 'sandstorm_titan', 'name' => 'Sandstorm Titan', 'glyph' => '💀', 'hp' => 8200, 'atk' => 430, 'gold' => 4200, 'xp' => 13500, 'gems' => 25, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('crimson_wastes'), 'min_level' => 198, 'enabled' => true, 'skills_json' => self::bossKit('Crimson Slam', 1.8, 'Bone Storm', 3, 'Desiccating Regeneration', 12), 'loot_table_json' => $lootFor('crimson_wastes', true)],

            // Emberfall Ridge [175, 200) — a bridge zone between Crimson Wastes (150) and Astral Expanse
            // (200), closing what used to be a 45-level gap with no new zone/dungeon at all.
            ['key' => 'cinder_stalker', 'name' => 'Cinder Stalker', 'glyph' => '🔥', 'hp' => 5300, 'atk' => 330, 'gold' => 2500, 'xp' => 8100, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('emberfall_ridge'), 'min_level' => 175, 'enabled' => true, 'skills_json' => self::normalKit('Cinder Slash', 1.6), 'loot_table_json' => $lootFor('emberfall_ridge', false)],
            ['key' => 'ashbound_wraith', 'name' => 'Ashbound Wraith', 'glyph' => '💨', 'hp' => 5900, 'atk' => 360, 'gold' => 2850, 'xp' => 9200, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('emberfall_ridge'), 'min_level' => 185, 'enabled' => true, 'skills_json' => self::normalKit('Ashen Curse', 1.6), 'loot_table_json' => $lootFor('emberfall_ridge', false)],
            ['key' => 'emberforged_colossus', 'name' => 'Emberforged Colossus', 'glyph' => '🗿', 'hp' => 6500, 'atk' => 390, 'gold' => 3100, 'xp' => 10000, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'tank', 'zone_id' => $zoneId('emberfall_ridge'), 'min_level' => 193, 'enabled' => true, 'skills_json' => self::normalKit('Molten Crush', 1.7, 1, 4), 'loot_table_json' => $lootFor('emberfall_ridge', false)],
            ['key' => 'ember_sovereign', 'name' => 'Ember Sovereign', 'glyph' => '☀', 'hp' => 9700, 'atk' => 520, 'gold' => 5500, 'xp' => 17500, 'gems' => 27, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('emberfall_ridge'), 'min_level' => 199, 'enabled' => true, 'skills_json' => self::bossKit('Inferno Collapse', 2.0, 'Cinder Barrage', 3, 'Emberforged Renewal', 15), 'loot_table_json' => $lootFor('emberfall_ridge', true)],

            // Astral Expanse [200, 250) — boss at 248 (Astral Emperor).
            ['key' => 'star_leech', 'name' => 'Star Leech', 'glyph' => '⭐', 'hp' => 6800, 'atk' => 380, 'gold' => 3300, 'xp' => 10500, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'caster', 'zone_id' => $zoneId('astral_expanse'), 'min_level' => 200, 'enabled' => true, 'skills_json' => self::normalKit('Starlight Drain', 0.6, 2), 'loot_table_json' => $lootFor('astral_expanse', false)],
            ['key' => 'astral_seraph', 'name' => 'Astral Seraph', 'glyph' => '🕊', 'hp' => 8200, 'atk' => 440, 'gold' => 4000, 'xp' => 12800, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'mender', 'zone_id' => $zoneId('astral_expanse'), 'min_level' => 217, 'enabled' => true, 'skills_json' => self::normalKit('Radiant Smite', 1.6), 'loot_table_json' => $lootFor('astral_expanse', false)],
            ['key' => 'comet_devourer', 'name' => 'Comet Devourer', 'glyph' => '☄', 'hp' => 9700, 'atk' => 500, 'gold' => 4800, 'xp' => 15500, 'gems' => 0, 'is_boss' => false, 'is_elite' => false, 'role' => 'skirmisher', 'zone_id' => $zoneId('astral_expanse'), 'min_level' => 233, 'enabled' => true, 'skills_json' => self::normalKit('Comet Crash', 1.7, 1, 4), 'loot_table_json' => $lootFor('astral_expanse', false)],
            ['key' => 'astral_emperor', 'name' => 'Astral Emperor', 'glyph' => '🌟', 'hp' => 13000, 'atk' => 620, 'gold' => 7200, 'xp' => 23000, 'gems' => 30, 'is_boss' => true, 'is_elite' => false, 'role' => 'elite_brute', 'zone_id' => $zoneId('astral_expanse'), 'min_level' => 248, 'enabled' => true, 'skills_json' => self::bossKit('Nova Burst', 2.0, 'Stellar Barrage', 3, 'Astral Renewal', 15), 'loot_table_json' => $lootFor('astral_expanse', true)],
        ];

        foreach ($monsters as $monster) {
            Monster::updateOrCreate(['key' => $monster['key']], $monster);
        }
    }
}
