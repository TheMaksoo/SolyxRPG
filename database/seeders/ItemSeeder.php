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
            ['key' => 'mana_potion', 'name' => 'Mana Potion', 'type' => 'consumable', 'rarity' => 'common', 'glyph' => '💧', 'description' => 'Restores 40% of your max MP instantly in battle.', 'stat_json' => ['heal_mp_pct' => 40], 'price_gold' => 50, 'price_gems' => null],
            ['key' => 'elixir_of_power', 'name' => 'Elixir of Power', 'type' => 'consumable', 'rarity' => 'legendary', 'glyph' => '⚗', 'description' => 'Grants +50% ATK for your next three fights.', 'stat_json' => ['atk_pct_buff' => 50, 'buff_fights' => 3], 'price_gold' => 300, 'price_gems' => null],
            ['key' => 'golden_crown', 'name' => 'Golden Crown', 'type' => 'cosmetic', 'rarity' => 'legendary', 'glyph' => '👑', 'description' => 'A pure flex — no combat stats, all prestige.', 'stat_json' => [], 'price_gold' => null, 'price_gems' => 200],
            ['key' => 'iron_ore', 'name' => 'Iron Ore', 'type' => 'material', 'rarity' => 'common', 'glyph' => '💎', 'description' => 'Basic crafting material dropped by golems and mined in caverns.', 'stat_json' => [], 'price_gold' => 10, 'price_gems' => null],
            ['key' => 'iron_dagger', 'name' => 'Iron Dagger', 'type' => 'weapon', 'rarity' => 'common', 'glyph' => '🗡', 'description' => 'A basic dagger, forged from crafting materials rather than bought.', 'stat_json' => ['atk' => 40], 'price_gold' => null, 'price_gems' => null],
        ];

        foreach ($items as $item) {
            Item::updateOrCreate(['key' => $item['key']], $item);
        }
    }
}
