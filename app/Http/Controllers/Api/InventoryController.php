<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Recipe;
use App\Services\DurabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    /** Fraction of a scrapped item's crafting materials refunded, rounded up per material. */
    private const SCRAP_REFUND_PCT = 0.05;

    public function __construct(private DurabilityService $durability) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('inventory', $request->user()), 403, 'Inventory is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }

    public function equip(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['item_id' => ['required', 'exists:items,id']]);

        $inventory = Inventory::where('character_id', $character->id)
            ->where('item_id', $data['item_id'])
            ->firstOrFail();

        $item = $inventory->item;
        // 'quiver' is the ranger's second slot (alongside their bow/weapon) — see ItemSeeder. Equipping one
        // only unequips other quivers below, never the weapon slot, so bow + quiver stay on together.
        if (! in_array($item->type, ['weapon', 'armor', 'pickaxe', 'axe', 'sickle', 'hammer', 'quiver'], true)) {
            return response()->json(['message' => 'Only weapons, armor, and tools can be equipped.'], 422);
        }

        // unequip anything else of the same type first (one weapon, one armor slot)
        Inventory::where('character_id', $character->id)
            ->where('equipped', true)
            ->whereHas('item', fn ($q) => $q->where('type', $item->type))
            ->update(['equipped' => false]);

        $inventory->update(['equipped' => true]);

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }

    public function unequip(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['item_id' => ['required', 'exists:items,id']]);

        $inventory = Inventory::where('character_id', $character->id)
            ->where('item_id', $data['item_id'])
            ->where('equipped', true)
            ->firstOrFail();

        $inventory->update(['equipped' => false]);

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }

    /** Permanently discards an inventory stack — the only way to clear out broken gear you don't want
     * to repair, or junk you don't want to sell/use. Deletes the whole row, not a partial quantity.
     * Refunds a slice of the materials that went into it, if it's a craftable item. */
    public function scrap(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['inventory_id' => ['required', 'exists:inventories,id']]);

        $inventory = Inventory::where('id', $data['inventory_id'])
            ->where('character_id', $character->id)
            ->with('item')
            ->firstOrFail();

        $refunded = $this->refundScrapMaterials($character, $inventory);
        $inventory->delete();

        return response()->json([
            'inventory' => $character->inventory()->with('item')->get(),
            'refunded' => $refunded,
        ]);
    }

    /** Crafted variants (rolled-stat gear) are their own Item row keyed "{baseKey}_crafted_{random}" —
     * trace back to the base item so its recipe's materials can be found. */
    private function baseItemFor(Item $item): Item
    {
        if (! str_contains($item->key, '_crafted_')) {
            return $item;
        }

        return Item::where('key', Str::before($item->key, '_crafted_'))->first() ?? $item;
    }

    private function refundScrapMaterials(Character $character, Inventory $inventory): array
    {
        $recipe = Recipe::where('result_item_id', $this->baseItemFor($inventory->item)->id)->first();
        if (! $recipe) {
            return [];
        }

        $refunded = [];
        foreach ($recipe->materials_json as $material) {
            $refundQty = (int) ceil($material['qty'] * self::SCRAP_REFUND_PCT * $inventory->qty);
            if ($refundQty <= 0) {
                continue;
            }

            $matInventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $material['item_id'], 'equipped' => false]);
            $matInventory->qty = ($matInventory->qty ?? 0) + $refundQty;
            $matInventory->save();

            $refunded[] = ['item_id' => $material['item_id'], 'qty' => $refundQty];
        }

        return $refunded;
    }

    /** Consumes an out-of-battle-usable potion — currently just the temporary HP/mana regen-rate buffs. */
    public function use(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['item_id' => ['required', 'exists:items,id']]);

        $inventory = Inventory::where('character_id', $character->id)
            ->where('item_id', $data['item_id'])
            ->firstOrFail();
        abort_if($inventory->qty < 1, 422, 'Out of that item.');

        $stats = $inventory->item->stat_json ?? [];
        $duration = $stats['duration_seconds'] ?? 600;
        $applied = [];

        if (isset($stats['hp_regen_pct_buff'])) {
            $character->hp_regen_buff_pct = $stats['hp_regen_pct_buff'];
            $character->hp_regen_buff_expires_at = now()->addSeconds($duration);
            $applied[] = "+{$stats['hp_regen_pct_buff']}% HP regen for ".intdiv($duration, 60).'m';
        }
        if (isset($stats['mana_regen_pct_buff'])) {
            $character->mana_regen_buff_pct = $stats['mana_regen_pct_buff'];
            $character->mana_regen_buff_expires_at = now()->addSeconds($duration);
            $applied[] = "+{$stats['mana_regen_pct_buff']}% mana regen for ".intdiv($duration, 60).'m';
        }

        if (! $applied) {
            return response()->json(['message' => 'This item can only be used in battle.'], 422);
        }

        $character->save();
        $inventory->decrement('qty');
        if ($inventory->qty <= 0) {
            $inventory->delete();
        }

        return response()->json([
            'character' => $character->fresh(['attributes_', 'inventory.item']),
            'applied' => $applied,
        ]);
    }

    /** Uses a repair pack on a specific gear instance. Chance of success and amount restored depend on pack grade vs. item grade. */
    public function repair(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'inventory_id' => ['required', 'exists:inventories,id'],
            'pack_item_id' => ['required', 'exists:items,id'],
        ]);

        $gear = Inventory::where('id', $data['inventory_id'])->where('character_id', $character->id)->with('item')->firstOrFail();
        abort_if($gear->durability_max === null, 422, 'That item has no durability to repair.');
        abort_if($gear->durability >= $gear->durability_max, 422, 'Already at full durability.');

        $pack = Inventory::where('character_id', $character->id)->where('item_id', $data['pack_item_id'])->with('item')->firstOrFail();
        abort_if($pack->item->type !== 'repair_pack', 422, 'That is not a repair pack.');
        abort_if($pack->qty < 1, 422, 'Out of that repair pack.');

        $outcome = $this->durability->repairOutcome($pack->item->rarity, $gear->item->rarity);
        $success = (mt_rand() / mt_getrandmax() * 100) < $outcome['chance_pct'];
        $restored = 0;

        if ($success) {
            $restored = (int) round($gear->durability_max * $outcome['repair_pct'] / 100);
            $gear->update(['durability' => min($gear->durability_max, $gear->durability + $restored)]);
        }

        $pack->decrement('qty');
        if ($pack->fresh()->qty <= 0) {
            $pack->delete();
        }

        return response()->json([
            'success' => $success,
            'chance_pct' => $outcome['chance_pct'],
            'restored' => $restored,
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }
}
