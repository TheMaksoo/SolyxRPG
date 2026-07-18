<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['key' => 'ashfang_blade', 'name' => 'Ashfang Blade', 'type' => 'weapon', 'rarity' => 'legendary', 'glyph' => '⚔', 'description' => 'A blade forged in dragonfire. The signature legendary weapon of Season 3.', 'stat_json' => ['atk' => 180, 'crit' => 12], 'price_gold' => null, 'price_gems' => 500],
            ['key' => 'shadow_dagger', 'name' => 'Shadow Dagger', 'type' => 'weapon', 'rarity' => 'epic', 'glyph' => '🗡', 'description' => 'A wickedly fast dagger that drains life on hit.', 'stat_json' => ['atk' => 120, 'lifesteal_pct' => 15], 'price_gold' => 900, 'price_gems' => null],
            ['key' => 'emberbow', 'name' => 'Emberbow', 'type' => 'weapon', 'rarity' => 'rare', 'glyph' => '🏹', 'description' => 'A longbow strung with molten sinew for precise ranged damage.', 'stat_json' => ['atk' => 95], 'price_gold' => 400, 'price_gems' => null],
            ['key' => 'void_staff', 'name' => 'Void Staff', 'type' => 'weapon', 'rarity' => 'epic', 'glyph' => '🔮', 'description' => 'Channels raw void energy into devastating spells.', 'stat_json' => ['atk' => 140], 'price_gold' => 700, 'price_gems' => null],
            ['key' => 'aegis_plate', 'name' => 'Aegis Plate', 'type' => 'armor', 'rarity' => 'legendary', 'glyph' => '🛡', 'description' => 'Impenetrable plate armor blessed by the old gods.', 'stat_json' => ['def' => 150], 'price_gold' => null, 'price_gems' => 600],
            ['key' => 'shadow_robe', 'name' => 'Shadow Robe', 'type' => 'armor', 'rarity' => 'epic', 'glyph' => '🥋', 'description' => 'Woven from shadowsilk; favored by mages.', 'stat_json' => ['def' => 90, 'mp' => 40], 'price_gold' => 800, 'price_gems' => null],
            ['key' => 'swift_boots', 'name' => 'Swift Boots', 'type' => 'armor', 'rarity' => 'rare', 'glyph' => '👢', 'description' => 'Enchanted boots that make you harder to hit.', 'stat_json' => ['dodge_pct' => 15], 'price_gold' => 350, 'price_gems' => null],
            ['key' => 'health_potion', 'name' => 'Health Potion', 'type' => 'consumable', 'rarity' => 'common', 'glyph' => '🧪', 'description' => 'Restores 40% of your max HP instantly in battle.', 'stat_json' => ['heal_hp_pct' => 40], 'price_gold' => 50, 'price_gems' => null],
            ['key' => 'greater_health_potion', 'name' => 'Greater Health Potion', 'type' => 'consumable', 'rarity' => 'rare', 'glyph' => '🧪', 'description' => 'Restores 70% of your max HP instantly in battle.', 'stat_json' => ['heal_hp_pct' => 70], 'price_gold' => 180, 'price_gems' => null],
            ['key' => 'mana_potion', 'name' => 'Mana Potion', 'type' => 'consumable', 'rarity' => 'common', 'glyph' => '💧', 'description' => 'Restores 40% of your max MP instantly in battle.', 'stat_json' => ['heal_mp_pct' => 40], 'price_gold' => 50, 'price_gems' => null],
            ['key' => 'greater_mana_potion', 'name' => 'Greater Mana Potion', 'type' => 'consumable', 'rarity' => 'rare', 'glyph' => '💧', 'description' => 'Restores 70% of your max MP instantly in battle.', 'stat_json' => ['heal_mp_pct' => 70], 'price_gold' => 180, 'price_gems' => null],
            ['key' => 'vitality_tonic', 'name' => 'Vitality Tonic', 'type' => 'consumable', 'rarity' => 'rare', 'glyph' => '🌿', 'description' => '+50% HP regen rate for 10 minutes. Usable anytime, even outside battle.', 'stat_json' => ['hp_regen_pct_buff' => 50, 'duration_seconds' => 600], 'price_gold' => 220, 'price_gems' => null],
            ['key' => 'focus_tonic', 'name' => 'Focus Tonic', 'type' => 'consumable', 'rarity' => 'rare', 'glyph' => '🔷', 'description' => '+50% mana regen rate for 10 minutes. Usable anytime, even outside battle.', 'stat_json' => ['mana_regen_pct_buff' => 50, 'duration_seconds' => 600], 'price_gold' => 220, 'price_gems' => null],
            ['key' => 'elixir_of_vigor', 'name' => 'Elixir of Vigor', 'type' => 'consumable', 'rarity' => 'epic', 'glyph' => '✨', 'description' => '+40% HP and mana regen rate for 15 minutes. Usable anytime, even outside battle.', 'stat_json' => ['hp_regen_pct_buff' => 40, 'mana_regen_pct_buff' => 40, 'duration_seconds' => 900], 'price_gold' => null, 'price_gems' => 120],
            ['key' => 'elixir_of_power', 'name' => 'Elixir of Power', 'type' => 'consumable', 'rarity' => 'legendary', 'glyph' => '⚗', 'description' => 'Grants +50% ATK for your next three fights.', 'stat_json' => ['atk_pct_buff' => 50, 'buff_fights' => 3], 'price_gold' => 300, 'price_gems' => null],
            ['key' => 'golden_crown', 'name' => 'Golden Crown', 'type' => 'cosmetic', 'rarity' => 'legendary', 'glyph' => '👑', 'description' => 'A pure flex — no combat stats, all prestige.', 'stat_json' => [], 'price_gold' => null, 'price_gems' => 200],
            ['key' => 'stone', 'name' => 'Stone', 'type' => 'material', 'rarity' => 'common', 'glyph' => '🪨', 'description' => 'Plain rock — all a fresh pickaxe turns up. Rank up Mining to start finding ore.', 'stat_json' => [], 'price_gold' => 5, 'price_gems' => null],
            ['key' => 'iron_ore', 'name' => 'Iron Ore', 'type' => 'material', 'rarity' => 'common', 'glyph' => '💎', 'description' => 'Basic mining material — unlocked at Mining level 8. Smelt it into bars before crafting.', 'stat_json' => [], 'price_gold' => 10, 'price_gems' => null],
            ['key' => 'silver_ore', 'name' => 'Silver Ore', 'type' => 'material', 'rarity' => 'epic', 'glyph' => '🔹', 'description' => 'A richer vein — unlocked at Mining level 20.', 'stat_json' => [], 'price_gold' => 30, 'price_gems' => null],
            ['key' => 'gold_ore', 'name' => 'Gold Ore', 'type' => 'material', 'rarity' => 'legendary', 'glyph' => '🟡', 'description' => 'A gleaming vein — unlocked at Mining level 35.', 'stat_json' => [], 'price_gold' => 120, 'price_gems' => null],
            ['key' => 'mythril_ore', 'name' => 'Mythril Ore', 'type' => 'material', 'rarity' => 'mythic', 'glyph' => '🔷', 'description' => 'A near-mythical vein — unlocked at Mining level 50.', 'stat_json' => [], 'price_gold' => 300, 'price_gems' => null],
            ['key' => 'wood', 'name' => 'Wood', 'type' => 'material', 'rarity' => 'common', 'glyph' => '🪵', 'description' => 'Plain lumber — all a fresh axe turns up. Rank up Woodchopping to start finding rarer timber.', 'stat_json' => [], 'price_gold' => 8, 'price_gems' => null],
            ['key' => 'oak_wood', 'name' => 'Oak Wood', 'type' => 'material', 'rarity' => 'common', 'glyph' => '🌳', 'description' => 'Sturdier timber — unlocked at Woodchopping level 8.', 'stat_json' => [], 'price_gold' => 18, 'price_gems' => null],
            ['key' => 'ironwood', 'name' => 'Ironwood', 'type' => 'material', 'rarity' => 'epic', 'glyph' => '🌲', 'description' => 'Dense, iron-hard timber — unlocked at Woodchopping level 20.', 'stat_json' => [], 'price_gold' => 28, 'price_gems' => null],
            ['key' => 'elderwood', 'name' => 'Elderwood', 'type' => 'material', 'rarity' => 'legendary', 'glyph' => '🍂', 'description' => 'Ancient, richly grained timber — unlocked at Woodchopping level 35.', 'stat_json' => [], 'price_gold' => 110, 'price_gems' => null],
            ['key' => 'moonwood', 'name' => 'Moonwood', 'type' => 'material', 'rarity' => 'mythic', 'glyph' => '🌙', 'description' => 'Pale, near-mythical timber — unlocked at Woodchopping level 50.', 'stat_json' => [], 'price_gold' => 280, 'price_gems' => null],
            ['key' => 'iron_bar', 'name' => 'Iron Bar', 'type' => 'material', 'rarity' => 'common', 'glyph' => '🔩', 'description' => 'Iron ore smelted at the forge, ready for crafting.', 'stat_json' => [], 'price_gold' => 25, 'price_gems' => null],
            ['key' => 'silver_bar', 'name' => 'Silver Bar', 'type' => 'material', 'rarity' => 'epic', 'glyph' => '⬜', 'description' => 'Silver ore smelted at the forge — unlocked at Smelting level 15.', 'stat_json' => [], 'price_gold' => 65, 'price_gems' => null],
            ['key' => 'gold_bar', 'name' => 'Gold Bar', 'type' => 'material', 'rarity' => 'legendary', 'glyph' => '🥇', 'description' => 'Gold ore smelted at the forge — unlocked at Smelting level 30.', 'stat_json' => [], 'price_gold' => 200, 'price_gems' => null],
            ['key' => 'mythril_bar', 'name' => 'Mythril Bar', 'type' => 'material', 'rarity' => 'mythic', 'glyph' => '💠', 'description' => 'Mythril ore smelted at the forge — unlocked at Smelting level 45.', 'stat_json' => [], 'price_gold' => 480, 'price_gems' => null],
            ['key' => 'stone_shiv', 'name' => 'Stone Shiv', 'type' => 'weapon', 'rarity' => 'common', 'glyph' => '🔪', 'description' => 'A crude blade knapped from raw stone. Cheap, quick, and available from day one.', 'stat_json' => ['atk' => 20], 'price_gold' => null, 'price_gems' => null],
            ['key' => 'iron_dagger', 'name' => 'Iron Dagger', 'type' => 'weapon', 'rarity' => 'common', 'glyph' => '🗡', 'description' => 'A basic dagger, forged from crafting materials rather than bought.', 'stat_json' => ['atk' => 40], 'price_gold' => null, 'price_gems' => null],
            ['key' => 'wooden_bow', 'name' => 'Wooden Bow', 'type' => 'weapon', 'rarity' => 'common', 'glyph' => '🏹', 'description' => 'A simple bow carved from chopped wood.', 'stat_json' => ['atk' => 45], 'price_gold' => null, 'price_gems' => null],
            ['key' => 'iron_buckler', 'name' => 'Iron Buckler', 'type' => 'armor', 'rarity' => 'common', 'glyph' => '🛡', 'description' => 'A sturdy buckler forged from iron bars.', 'stat_json' => ['def' => 35], 'price_gold' => null, 'price_gems' => null],
            ['key' => 'silvered_blade', 'name' => 'Silvered Blade', 'type' => 'weapon', 'rarity' => 'epic', 'glyph' => '⚔', 'description' => 'A blade tempered with silver — a real test of your Crafting rank.', 'stat_json' => ['atk' => 110, 'crit' => 4], 'price_gold' => null, 'price_gems' => null],
            ['key' => 'gilded_saber', 'name' => 'Gilded Saber', 'type' => 'weapon', 'rarity' => 'legendary', 'glyph' => '⚔', 'description' => 'A saber cast in gold and bound in elderwood — a serious material investment.', 'stat_json' => ['atk' => 150, 'crit' => 8], 'price_gold' => null, 'price_gems' => null],
            ['key' => 'mythril_aegis', 'name' => 'Mythril Aegis', 'type' => 'armor', 'rarity' => 'mythic', 'glyph' => '🛡', 'description' => 'A ward-etched aegis of mythril and moonwood — the pinnacle of the forge.', 'stat_json' => ['def' => 220], 'price_gold' => null, 'price_gems' => null],
        ];

        foreach ($items as $item) {
            Item::updateOrCreate(['key' => $item['key']], $item);
        }
    }
}
