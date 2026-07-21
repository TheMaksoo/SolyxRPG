<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Recipe;
use Illuminate\Database\Seeder;

class RecipeSeeder extends Seeder
{
    /** Every weapon/armor tier draws from exactly ONE material — the same "one resource per tier" chain
     * repair packs already use (stone → iron_bar → silver_bar → gold_bar → mythril_bar) — instead of the
     * mixed-material recipes gear used to have. Quantities are set so the raw material cost of ONE craft
     * clears 10 uses' worth of that tier's repair pack (a batch of 5 costs [common 10 stone, rare 6
     * iron_bar, epic 4 silver_bar, legendary 3 gold_bar, mythic 3 mythril_bar] — so 10 uses is [20, 12, 8,
     * 6, 6]); craft time is roughly double the previous pass's pacing. gold_cost is unchanged in direction
     * from the last balance pass — this pass is entirely about resource/time cost, not gold. */
    public function run(): void
    {
        $ids = Item::whereIn('key', [
            'stone', 'iron_bar', 'wood', 'oak_wood', 'silver_bar', 'gold_bar', 'mythril_bar', 'ironwood', 'elderwood', 'moonwood',
            'iron_sword', 'iron_dagger', 'wooden_bow', 'oak_staff',
            'steel_broadsword', 'serrated_kris', 'recurve_longbow', 'ashwood_staff',
            'silvered_blade', 'silvered_fang', 'silvered_recurve', 'silvertide_staff',
            'gilded_saber', 'gilded_fang', 'gilded_war_bow', 'gilded_spire_staff',
            'mythril_greatblade', 'mythril_fang', 'mythril_war_bow', 'mythril_spire_staff',
            'iron_buckler', 'plate_hauberk', 'leather_vest', 'ranger_cloak', 'padded_robe',
            'banded_cuirass', 'shadowweave_jerkin', 'fletchers_cloak', 'woven_mana_robe',
            'silverplate_harness', 'nightsilver_leathers', 'silverleaf_cloak', 'silverweave_robe',
            'aegis_plate', 'duskblade_leathers', 'stormwatch_cloak', 'gilded_aether_robe',
            'mythril_aegis', 'titanplate_cuirass', 'voidsilk_leathers', 'wraithwind_cloak', 'mythril_aether_robe',
            'fletchers_quiver',
            'stone_pickaxe', 'iron_pickaxe', 'silver_pickaxe', 'gold_pickaxe', 'mythril_pickaxe',
            'wood_axe', 'oak_axe', 'ironwood_axe', 'elderwood_axe', 'moonwood_axe',
            'stone_sickle', 'iron_sickle', 'silver_sickle', 'gold_sickle', 'mythril_sickle',
            'wood_hammer', 'iron_hammer', 'silver_hammer', 'gold_hammer', 'mythril_hammer',
            'herb', 'sage_leaf', 'moonpetal', 'sunroot', 'phoenix_bloom',
            'herbal_poultice', 'herbal_draught', 'sage_poultice', 'sage_draught', 'sage_tonic',
            'moonpetal_poultice', 'moonpetal_draught', 'moonpetal_tonic',
            'sunroot_poultice', 'sunroot_draught', 'sunroot_tonic',
            'phoenix_elixir', 'phoenix_draught', 'phoenix_tonic',
            'common_repair_pack', 'rare_repair_pack', 'epic_repair_pack', 'legendary_repair_pack', 'mythic_repair_pack',
        ])->pluck('id', 'key');

        // level => [material, qty, craft_seconds, gold_cost] — the single-material tier chain every
        // weapon/armor recipe below draws from, one tier per class per level band.
        $tier = [
            8 => ['stone', 30, 150, 630],
            15 => ['iron_bar', 22, 260, 2200],
            20 => ['silver_bar', 16, 380, 6200],
            35 => ['gold_bar', 12, 560, 18200],
            50 => ['mythril_bar', 12, 800, 46300],
        ];

        $gearFor = fn (string $label, string $result, int $level) => [
            'name' => "Craft {$label}",
            'result' => $result,
            'materials' => [['item' => $tier[$level][0], 'qty' => $tier[$level][1]]],
            'craft_seconds' => $tier[$level][2],
            'min_level' => $level,
            'gold_cost' => $tier[$level][3],
        ];

        $recipes = [
            // ---- Level 1 ----
            ['name' => 'Craft Stone Pickaxe', 'result' => 'stone_pickaxe', 'materials' => [['item' => 'stone', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1, 'gold_cost' => 300],
            ['name' => 'Craft Wood Axe', 'result' => 'wood_axe', 'materials' => [['item' => 'wood', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1, 'gold_cost' => 300],
            ['name' => 'Craft Stone Sickle', 'result' => 'stone_sickle', 'materials' => [['item' => 'stone', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1, 'gold_cost' => 300],
            ['name' => 'Craft Wood Hammer', 'result' => 'wood_hammer', 'materials' => [['item' => 'wood', 'qty' => 8]], 'craft_seconds' => 40, 'min_level' => 1, 'gold_cost' => 300],
            ['name' => 'Brew Herbal Poultice', 'result' => 'herbal_poultice', 'materials' => [['item' => 'herb', 'qty' => 6]], 'craft_seconds' => 35, 'min_level' => 1],
            ['name' => 'Brew Herbal Draught', 'result' => 'herbal_draught', 'materials' => [['item' => 'herb', 'qty' => 6]], 'craft_seconds' => 35, 'min_level' => 1],
            ['name' => 'Craft Common Repair Pack', 'result' => 'common_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'stone', 'qty' => 10]], 'craft_seconds' => 30, 'min_level' => 1],

            // ---- Level 8 (common tier — 1 weapon + 1 armor per class, stone only) ----
            $gearFor('Iron Sword', 'iron_sword', 8),
            $gearFor('Iron Dagger', 'iron_dagger', 8),
            $gearFor('Wooden Bow', 'wooden_bow', 8),
            $gearFor('Oak Staff', 'oak_staff', 8),
            $gearFor('Plate Hauberk', 'plate_hauberk', 8),
            $gearFor('Leather Vest', 'leather_vest', 8),
            $gearFor("Ranger's Cloak", 'ranger_cloak', 8),
            $gearFor('Padded Robe', 'padded_robe', 8),
            // Warrior's shield (2nd slot) and Ranger's quiver (2nd slot) — kept at their own established
            // mixed-material recipes rather than folded into the single-material gear chain above, since
            // neither was part of this pass's "weapon + armor" scope.
            ['name' => 'Craft Iron Buckler', 'result' => 'iron_buckler', 'materials' => [['item' => 'iron_bar', 'qty' => 10]], 'craft_seconds' => 100, 'min_level' => 8, 'gold_cost' => 630],
            ['name' => "Craft Fletcher's Quiver", 'result' => 'fletchers_quiver', 'materials' => [['item' => 'wood', 'qty' => 14], ['item' => 'iron_bar', 'qty' => 2]], 'craft_seconds' => 60, 'min_level' => 8, 'gold_cost' => 630],
            ['name' => 'Craft Iron Pickaxe', 'result' => 'iron_pickaxe', 'materials' => [['item' => 'iron_bar', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8, 'gold_cost' => 700],
            ['name' => 'Craft Oak Axe', 'result' => 'oak_axe', 'materials' => [['item' => 'oak_wood', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8, 'gold_cost' => 700],
            ['name' => 'Craft Iron Sickle', 'result' => 'iron_sickle', 'materials' => [['item' => 'iron_bar', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8, 'gold_cost' => 700],
            ['name' => 'Craft Iron Hammer', 'result' => 'iron_hammer', 'materials' => [['item' => 'iron_bar', 'qty' => 5]], 'craft_seconds' => 75, 'min_level' => 8, 'gold_cost' => 700],
            ['name' => 'Brew Sage Poultice', 'result' => 'sage_poultice', 'materials' => [['item' => 'sage_leaf', 'qty' => 5], ['item' => 'herb', 'qty' => 3]], 'craft_seconds' => 110, 'min_level' => 8],
            ['name' => 'Brew Sage Draught', 'result' => 'sage_draught', 'materials' => [['item' => 'sage_leaf', 'qty' => 5], ['item' => 'herb', 'qty' => 3]], 'craft_seconds' => 110, 'min_level' => 8],
            ['name' => 'Brew Sage Tonic', 'result' => 'sage_tonic', 'materials' => [['item' => 'sage_leaf', 'qty' => 5], ['item' => 'herb', 'qty' => 3]], 'craft_seconds' => 110, 'min_level' => 8],
            ['name' => 'Craft Rare Repair Pack', 'result' => 'rare_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'iron_bar', 'qty' => 6]], 'craft_seconds' => 45, 'min_level' => 8],

            // ---- Level 15 (rare tier — iron_bar only) ----
            $gearFor('Steel Broadsword', 'steel_broadsword', 15),
            $gearFor('Serrated Kris', 'serrated_kris', 15),
            $gearFor('Recurve Longbow', 'recurve_longbow', 15),
            $gearFor('Ashwood Staff', 'ashwood_staff', 15),
            $gearFor('Banded Cuirass', 'banded_cuirass', 15),
            $gearFor('Shadowweave Jerkin', 'shadowweave_jerkin', 15),
            $gearFor("Fletcher's Cloak", 'fletchers_cloak', 15),
            $gearFor('Woven Mana Robe', 'woven_mana_robe', 15),

            // ---- Level 20 (epic tier — silver_bar only) ----
            $gearFor('Silvered Broadsword', 'silvered_blade', 20),
            $gearFor('Silvered Fang', 'silvered_fang', 20),
            $gearFor('Silvered Recurve', 'silvered_recurve', 20),
            $gearFor('Silvertide Staff', 'silvertide_staff', 20),
            $gearFor('Silverplate Harness', 'silverplate_harness', 20),
            $gearFor('Nightsilver Leathers', 'nightsilver_leathers', 20),
            $gearFor('Silverleaf Cloak', 'silverleaf_cloak', 20),
            $gearFor('Silverweave Robe', 'silverweave_robe', 20),
            ['name' => 'Craft Silver Pickaxe', 'result' => 'silver_pickaxe', 'materials' => [['item' => 'silver_bar', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20, 'gold_cost' => 6200],
            ['name' => 'Craft Ironwood Axe', 'result' => 'ironwood_axe', 'materials' => [['item' => 'ironwood', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20, 'gold_cost' => 6200],
            ['name' => 'Craft Silver Sickle', 'result' => 'silver_sickle', 'materials' => [['item' => 'silver_bar', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20, 'gold_cost' => 6200],
            ['name' => 'Craft Silver Hammer', 'result' => 'silver_hammer', 'materials' => [['item' => 'silver_bar', 'qty' => 5]], 'craft_seconds' => 140, 'min_level' => 20, 'gold_cost' => 6200],
            ['name' => 'Craft Epic Repair Pack', 'result' => 'epic_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'silver_bar', 'qty' => 4]], 'craft_seconds' => 65, 'min_level' => 20],
            ['name' => 'Brew Moonpetal Poultice', 'result' => 'moonpetal_poultice', 'materials' => [['item' => 'moonpetal', 'qty' => 5], ['item' => 'sage_leaf', 'qty' => 3]], 'craft_seconds' => 200, 'min_level' => 20],
            ['name' => 'Brew Moonpetal Draught', 'result' => 'moonpetal_draught', 'materials' => [['item' => 'moonpetal', 'qty' => 5], ['item' => 'sage_leaf', 'qty' => 3]], 'craft_seconds' => 200, 'min_level' => 20],
            ['name' => 'Brew Moonpetal Tonic', 'result' => 'moonpetal_tonic', 'materials' => [['item' => 'moonpetal', 'qty' => 5], ['item' => 'sage_leaf', 'qty' => 3]], 'craft_seconds' => 200, 'min_level' => 20],

            // ---- Level 35 (legendary tier — gold_bar only) ----
            $gearFor('Gilded Broadsword', 'gilded_saber', 35),
            $gearFor('Gilded Fang', 'gilded_fang', 35),
            $gearFor('Gilded War Bow', 'gilded_war_bow', 35),
            $gearFor('Gilded Spire Staff', 'gilded_spire_staff', 35),
            $gearFor('Aegis Plate', 'aegis_plate', 35),
            $gearFor('Duskblade Leathers', 'duskblade_leathers', 35),
            $gearFor('Stormwatch Cloak', 'stormwatch_cloak', 35),
            $gearFor('Gilded Aether Robe', 'gilded_aether_robe', 35),
            ['name' => 'Craft Gold Pickaxe', 'result' => 'gold_pickaxe', 'materials' => [['item' => 'gold_bar', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35, 'gold_cost' => 18200],
            ['name' => 'Craft Elderwood Axe', 'result' => 'elderwood_axe', 'materials' => [['item' => 'elderwood', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35, 'gold_cost' => 18200],
            ['name' => 'Craft Gold Sickle', 'result' => 'gold_sickle', 'materials' => [['item' => 'gold_bar', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35, 'gold_cost' => 18200],
            ['name' => 'Craft Gold Hammer', 'result' => 'gold_hammer', 'materials' => [['item' => 'gold_bar', 'qty' => 5]], 'craft_seconds' => 220, 'min_level' => 35, 'gold_cost' => 18200],
            ['name' => 'Craft Legendary Repair Pack', 'result' => 'legendary_repair_pack', 'result_qty' => 5, 'materials' => [['item' => 'gold_bar', 'qty' => 3]], 'craft_seconds' => 90, 'min_level' => 35],
            ['name' => 'Brew Sunroot Poultice', 'result' => 'sunroot_poultice', 'materials' => [['item' => 'sunroot', 'qty' => 5], ['item' => 'moonpetal', 'qty' => 3]], 'craft_seconds' => 320, 'min_level' => 35],
            ['name' => 'Brew Sunroot Draught', 'result' => 'sunroot_draught', 'materials' => [['item' => 'sunroot', 'qty' => 5], ['item' => 'moonpetal', 'qty' => 3]], 'craft_seconds' => 320, 'min_level' => 35],
            ['name' => 'Brew Sunroot Tonic', 'result' => 'sunroot_tonic', 'materials' => [['item' => 'sunroot', 'qty' => 5], ['item' => 'moonpetal', 'qty' => 3]], 'craft_seconds' => 320, 'min_level' => 35],

            // ---- Level 50 (mythic tier — mythril_bar only) ----
            $gearFor('Mythril Greatblade', 'mythril_greatblade', 50),
            $gearFor('Mythril Fang', 'mythril_fang', 50),
            $gearFor('Mythril War Bow', 'mythril_war_bow', 50),
            $gearFor('Mythril Spire Staff', 'mythril_spire_staff', 50),
            $gearFor('Titanplate Cuirass', 'titanplate_cuirass', 50),
            $gearFor('Voidsilk Leathers', 'voidsilk_leathers', 50),
            $gearFor('Wraithwind Cloak', 'wraithwind_cloak', 50),
            $gearFor('Mythril Aether Robe', 'mythril_aether_robe', 50),
            ['name' => 'Craft Mythril Aegis', 'result' => 'mythril_aegis', 'materials' => [['item' => 'mythril_bar', 'qty' => 11], ['item' => 'moonwood', 'qty' => 9]], 'craft_seconds' => 480, 'min_level' => 50, 'gold_cost' => 46200],
            ['name' => 'Craft Mythril Pickaxe', 'result' => 'mythril_pickaxe', 'materials' => [['item' => 'mythril_bar', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50, 'gold_cost' => 46300],
            ['name' => 'Craft Moonwood Axe', 'result' => 'moonwood_axe', 'materials' => [['item' => 'moonwood', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50, 'gold_cost' => 46300],
            ['name' => 'Craft Mythril Sickle', 'result' => 'mythril_sickle', 'materials' => [['item' => 'mythril_bar', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50, 'gold_cost' => 46300],
            ['name' => 'Craft Mythril Hammer', 'result' => 'mythril_hammer', 'materials' => [['item' => 'mythril_bar', 'qty' => 5]], 'craft_seconds' => 400, 'min_level' => 50, 'gold_cost' => 46300],
            ['name' => 'Brew Phoenix Elixir', 'result' => 'phoenix_elixir', 'materials' => [['item' => 'phoenix_bloom', 'qty' => 4], ['item' => 'sunroot', 'qty' => 3]], 'craft_seconds' => 420, 'min_level' => 50],
            ['name' => 'Brew Phoenix Draught', 'result' => 'phoenix_draught', 'materials' => [['item' => 'phoenix_bloom', 'qty' => 4], ['item' => 'sunroot', 'qty' => 3]], 'craft_seconds' => 420, 'min_level' => 50],
            ['name' => 'Brew Phoenix Tonic', 'result' => 'phoenix_tonic', 'materials' => [['item' => 'phoenix_bloom', 'qty' => 4], ['item' => 'sunroot', 'qty' => 3]], 'craft_seconds' => 420, 'min_level' => 50],
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
