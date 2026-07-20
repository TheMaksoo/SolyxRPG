<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Recipe;
use Illuminate\Database\Seeder;

class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $ids = Item::whereIn('key', [
            'stone', 'iron_bar', 'wood', 'oak_wood', 'silver_bar', 'gold_bar', 'mythril_bar', 'ironwood', 'elderwood', 'moonwood',
            'stone_shiv', 'iron_sword', 'iron_dagger', 'wooden_bow', 'oak_staff',
            'iron_buckler', 'leather_vest', 'ranger_cloak', 'padded_robe',
            'silvered_blade', 'gilded_saber', 'mythril_aegis',
            'stone_pickaxe', 'iron_pickaxe', 'silver_pickaxe', 'gold_pickaxe', 'mythril_pickaxe',
            'wood_axe', 'oak_axe', 'ironwood_axe', 'elderwood_axe', 'moonwood_axe',
            'stone_sickle', 'iron_sickle', 'silver_sickle', 'gold_sickle', 'mythril_sickle',
            'wood_hammer', 'iron_hammer', 'silver_hammer', 'gold_hammer', 'mythril_hammer',
            'herb', 'sage_leaf', 'sunroot', 'phoenix_bloom', 'herbal_poultice', 'sage_tonic', 'phoenix_elixir',
            'common_repair_pack', 'rare_repair_pack', 'epic_repair_pack', 'legendary_repair_pack', 'mythic_repair_pack',
        ])->pluck('id', 'key');

        $recipes = [
            // Common tier — available from level 1, no smelting required.
            ['name' => 'Craft Stone Shiv', 'result' => 'stone_shiv', 'materials' => [['item' => 'stone', 'qty' => 6]], 'craft_seconds' => 35, 'min_level' => 1, 'gold_cost' => 50],
            // Class-specific common weapons — same iron_bar cost/timing as the old shared line, just re-flavored.
            ['name' => 'Craft Iron Sword', 'result' => 'iron_sword', 'materials' => [['item' => 'iron_bar', 'qty' => 3]], 'craft_seconds' => 65, 'min_level' => 8, 'gold_cost' => 120],
            ['name' => 'Craft Iron Dagger', 'result' => 'iron_dagger', 'materials' => [['item' => 'iron_bar', 'qty' => 3]], 'craft_seconds' => 65, 'min_level' => 8, 'gold_cost' => 120],
            ['name' => 'Craft Wooden Bow', 'result' => 'wooden_bow', 'materials' => [['item' => 'wood', 'qty' => 8]], 'craft_seconds' => 60, 'min_level' => 8, 'gold_cost' => 120],
            ['name' => 'Craft Oak Staff', 'result' => 'oak_staff', 'materials' => [['item' => 'oak_wood', 'qty' => 5], ['item' => 'iron_bar', 'qty' => 1]], 'craft_seconds' => 65, 'min_level' => 8, 'gold_cost' => 120],
            // Class-specific common armor.
            ['name' => 'Craft Iron Buckler', 'result' => 'iron_buckler', 'materials' => [['item' => 'iron_bar', 'qty' => 4]], 'craft_seconds' => 100, 'min_level' => 8, 'gold_cost' => 120],
            ['name' => 'Craft Leather Vest', 'result' => 'leather_vest', 'materials' => [['item' => 'iron_bar', 'qty' => 2], ['item' => 'wood', 'qty' => 6]], 'craft_seconds' => 100, 'min_level' => 8, 'gold_cost' => 120],
            ['name' => "Craft Ranger's Cloak", 'result' => 'ranger_cloak', 'materials' => [['item' => 'oak_wood', 'qty' => 6], ['item' => 'iron_bar', 'qty' => 1]], 'craft_seconds' => 100, 'min_level' => 8, 'gold_cost' => 120],
            ['name' => 'Craft Padded Robe', 'result' => 'padded_robe', 'materials' => [['item' => 'iron_bar', 'qty' => 2], ['item' => 'oak_wood', 'qty' => 4]], 'craft_seconds' => 100, 'min_level' => 8, 'gold_cost' => 120],
            // Mid tier — needs Smelting rank for silver, min_level matches the materials' own unlock ranks.
            ['name' => 'Craft Silvered Blade', 'result' => 'silvered_blade', 'materials' => [['item' => 'silver_bar', 'qty' => 3], ['item' => 'iron_bar', 'qty' => 2], ['item' => 'ironwood', 'qty' => 2]], 'craft_seconds' => 220, 'min_level' => 20, 'gold_cost' => 1400],
            // High tier — gold/mythril only reachable at high Mining/Smelting rank, and cost far more materials.
            ['name' => 'Craft Gilded Saber', 'result' => 'gilded_saber', 'materials' => [['item' => 'gold_bar', 'qty' => 4], ['item' => 'elderwood', 'qty' => 3]], 'craft_seconds' => 320, 'min_level' => 35, 'gold_cost' => 4500],
            ['name' => 'Craft Mythril Aegis', 'result' => 'mythril_aegis', 'materials' => [['item' => 'mythril_bar', 'qty' => 5], ['item' => 'moonwood', 'qty' => 4]], 'craft_seconds' => 480, 'min_level' => 50, 'gold_cost' => 12000],
            // Tools — one per ore/wood tier, each boosting its matching gathering skill's speed and yield.
            ['name' => 'Craft Stone Pickaxe', 'result' => 'stone_pickaxe', 'materials' => [['item' => 'stone', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1],
            ['name' => 'Craft Iron Pickaxe', 'result' => 'iron_pickaxe', 'materials' => [['item' => 'iron_bar', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8],
            ['name' => 'Craft Silver Pickaxe', 'result' => 'silver_pickaxe', 'materials' => [['item' => 'silver_bar', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20],
            ['name' => 'Craft Gold Pickaxe', 'result' => 'gold_pickaxe', 'materials' => [['item' => 'gold_bar', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35],
            ['name' => 'Craft Mythril Pickaxe', 'result' => 'mythril_pickaxe', 'materials' => [['item' => 'mythril_bar', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50],
            ['name' => 'Craft Wood Axe', 'result' => 'wood_axe', 'materials' => [['item' => 'wood', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1],
            ['name' => 'Craft Oak Axe', 'result' => 'oak_axe', 'materials' => [['item' => 'oak_wood', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8],
            ['name' => 'Craft Ironwood Axe', 'result' => 'ironwood_axe', 'materials' => [['item' => 'ironwood', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20],
            ['name' => 'Craft Elderwood Axe', 'result' => 'elderwood_axe', 'materials' => [['item' => 'elderwood', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35],
            ['name' => 'Craft Moonwood Axe', 'result' => 'moonwood_axe', 'materials' => [['item' => 'moonwood', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50],
            ['name' => 'Craft Stone Sickle', 'result' => 'stone_sickle', 'materials' => [['item' => 'stone', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1],
            ['name' => 'Craft Iron Sickle', 'result' => 'iron_sickle', 'materials' => [['item' => 'iron_bar', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8],
            ['name' => 'Craft Silver Sickle', 'result' => 'silver_sickle', 'materials' => [['item' => 'silver_bar', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20],
            ['name' => 'Craft Gold Sickle', 'result' => 'gold_sickle', 'materials' => [['item' => 'gold_bar', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35],
            ['name' => 'Craft Mythril Sickle', 'result' => 'mythril_sickle', 'materials' => [['item' => 'mythril_bar', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50],
            ['name' => 'Craft Wood Hammer', 'result' => 'wood_hammer', 'materials' => [['item' => 'wood', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1],
            ['name' => 'Craft Iron Hammer', 'result' => 'iron_hammer', 'materials' => [['item' => 'iron_bar', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8],
            ['name' => 'Craft Silver Hammer', 'result' => 'silver_hammer', 'materials' => [['item' => 'silver_bar', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20],
            ['name' => 'Craft Gold Hammer', 'result' => 'gold_hammer', 'materials' => [['item' => 'gold_bar', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35],
            ['name' => 'Craft Mythril Hammer', 'result' => 'mythril_hammer', 'materials' => [['item' => 'mythril_bar', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50],
            // Potions — brewed from foraged herbs.
            ['name' => 'Brew Herbal Poultice', 'result' => 'herbal_poultice', 'materials' => [['item' => 'herb', 'qty' => 6]], 'craft_seconds' => 35, 'min_level' => 1],
            ['name' => 'Brew Sage Tonic', 'result' => 'sage_tonic', 'materials' => [['item' => 'sage_leaf', 'qty' => 5], ['item' => 'herb', 'qty' => 3]], 'craft_seconds' => 110, 'min_level' => 8],
            ['name' => 'Brew Phoenix Elixir', 'result' => 'phoenix_elixir', 'materials' => [['item' => 'phoenix_bloom', 'qty' => 4], ['item' => 'sunroot', 'qty' => 3]], 'craft_seconds' => 420, 'min_level' => 50],
            // Repair packs — crafted in a batch of 5 per go (they're single-use consumables, not gear), one tier
            // per ore rank mirroring the pickaxe/axe progression. Batch time is set per-batch, not per-unit.
            ['name' => 'Craft Common Repair Pack', 'result' => 'common_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'stone', 'qty' => 10]], 'craft_seconds' => 30, 'min_level' => 1],
            ['name' => 'Craft Rare Repair Pack', 'result' => 'rare_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'iron_bar', 'qty' => 6]], 'craft_seconds' => 45, 'min_level' => 8],
            ['name' => 'Craft Epic Repair Pack', 'result' => 'epic_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'silver_bar', 'qty' => 4]], 'craft_seconds' => 65, 'min_level' => 20],
            ['name' => 'Craft Legendary Repair Pack', 'result' => 'legendary_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'gold_bar', 'qty' => 3]], 'craft_seconds' => 90, 'min_level' => 35],
            ['name' => 'Craft Mythic Repair Pack', 'result' => 'mythic_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'mythril_bar', 'qty' => 3]], 'craft_seconds' => 130, 'min_level' => 50],
        ];

        foreach ($recipes as $recipe) {
            Recipe::updateOrCreate(
                ['name' => $recipe['name']],
                [
                    'result_item_id' => $ids[$recipe['result']],
                    'materials_json' => collect($recipe['materials'])->map(fn (array $m) => ['item_id' => $ids[$m['item']], 'qty' => $m['qty']])->all(),
                    'craft_seconds' => $recipe['craft_seconds'],
                    'result_qty' => $recipe['result_qty'] ?? 1,
                    'min_level' => $recipe['min_level'],
                    'gold_cost' => $recipe['gold_cost'] ?? 0,
                    'enabled' => true,
                ]
            );
        }
    }
}
