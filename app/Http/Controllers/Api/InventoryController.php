<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CraftingJob;
use App\Models\FeatureFlag;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Recipe;
use App\Services\DurabilityService;
use App\Services\ReforgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    /** Fraction of a scrapped item's crafting materials refunded, rounded up per material. Deliberately
     * small — scrapping a roll you don't like and recrafting should cost noticeably more than repairing
     * the one you have (see DurabilityService::REPAIR_PACK_TIERS), not be a cheap way to keep re-rolling. */
    private const SCRAP_REFUND_PCT = 0.03;

    public function __construct(private DurabilityService $durability, private ReforgeService $reforge = new ReforgeService()) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('inventory', $request->user()), 403, 'Inventory is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }

    /** How many of a given equip type a character can have equipped at once — 1 for every slot except
     * mage's Trinket slot, which is 2 (mages have no shield/quiver-equivalent 2nd combat slot of their
     * own, so their Trinket line doubles up to give them a comparable amount of gear choice). */
    private function equipSlotCapacity(Character $character, string $type): int
    {
        return $type === 'trinket' && $character->base_class === 'mage' ? 2 : 1;
    }

    /** Ordered slot keys for a multi-capacity type, e.g. ['trinket_1', 'trinket_2'] — stored on
     * Inventory::slot (an existing, previously-unused column) so each equipped copy stays pinned to the
     * exact doll slot the player put it in, instead of the two being an arbitrary, order-dependent pair. */
    private function slotKeysFor(string $type, int $capacity): array
    {
        return $capacity <= 1 ? [$type] : array_map(fn ($n) => "{$type}_{$n}", range(1, $capacity));
    }

    public function equip(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'item_id' => ['required', 'exists:items,id'],
            'slot_key' => ['nullable', 'string'],
        ]);

        $inventory = Inventory::where('character_id', $character->id)
            ->where('item_id', $data['item_id'])
            ->firstOrFail();

        $item = $inventory->item;
        // 'quiver' is the ranger's/rogue's shared second slot (alongside their bow or dagger), 'shield'
        // is the warrior's (alongside their chest armor) — see ItemSeeder. Equipping one only unequips
        // other same-type items below, never the weapon/armor slot, so bow+quiver, dagger+knife holder,
        // or armor+shield stay on together.
        if (! in_array($item->type, ['weapon', 'armor', 'shield', 'pickaxe', 'axe', 'sickle', 'hammer', 'quiver', 'trinket'], true)) {
            return response()->json(['message' => 'Only weapons, armor, trinkets, and tools can be equipped.'], 422);
        }

        // Any class can craft or buy another class's gear (e.g. to resell on the Marketplace — see
        // CraftingController's 'other_class' flag), but only their own class can actually wear it.
        // Quivers are the one exception: Rangers and Rogues share the slot (both classes can equip either
        // line — the ranger-flavored Quiver or the rogue-flavored Knife Holder — regardless of which
        // class the specific item's class_key was seeded for).
        $wearableClasses = $item->type === 'quiver' ? ['ranger', 'rogue'] : [$item->class_key];
        if ($item->class_key !== null && ! in_array($character->base_class, $wearableClasses, true)) {
            return response()->json(['message' => "This gear is for the {$item->class_key} class."], 422);
        }

        $capacity = $this->equipSlotCapacity($character, $item->type);

        if ($capacity <= 1) {
            // unequip anything else of the same type first (one weapon, one armor slot)
            Inventory::where('character_id', $character->id)
                ->where('equipped', true)
                ->whereHas('item', fn ($q) => $q->where('type', $item->type))
                ->update(['equipped' => false, 'slot' => null]);

            $inventory->update(['equipped' => true, 'slot' => $item->type]);

            return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
        }

        $slotKeys = $this->slotKeysFor($item->type, $capacity);
        $equippedOfType = Inventory::where('character_id', $character->id)
            ->where('equipped', true)
            ->whereHas('item', fn ($q) => $q->where('type', $item->type))
            ->get();

        $requestedSlot = $data['slot_key'] ?? null;
        if ($requestedSlot !== null && ! in_array($requestedSlot, $slotKeys, true)) {
            return response()->json(['message' => 'Invalid slot.'], 422);
        }

        if ($requestedSlot === null) {
            $occupied = $equippedOfType->pluck('slot')->filter()->all();
            $requestedSlot = collect($slotKeys)->first(fn ($key) => ! in_array($key, $occupied, true));
            if ($requestedSlot === null) {
                return response()->json(['message' => "Both {$item->type} slots are full — unequip one first."], 422);
            }
        }

        // Bump whatever currently occupies that specific slot (if anything) rather than the older/newer
        // one arbitrarily — the player picked this exact slot to fill.
        Inventory::where('character_id', $character->id)
            ->where('equipped', true)
            ->where('slot', $requestedSlot)
            ->where('id', '!=', $inventory->id)
            ->update(['equipped' => false, 'slot' => null]);

        $inventory->update(['equipped' => true, 'slot' => $requestedSlot]);

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }

    public function unequip(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['inventory_id' => ['required', 'exists:inventories,id']]);

        $inventory = Inventory::where('id', $data['inventory_id'])
            ->where('character_id', $character->id)
            ->where('equipped', true)
            ->firstOrFail();

        $inventory->update(['equipped' => false, 'slot' => null]);

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }

    /** Permanently discards some or all of an inventory stack — the only way to clear out broken gear you
     * don't want to repair, or junk you don't want to sell/use. `qty` defaults to (and is capped at) the
     * whole stack; the row is only deleted once its qty hits 0, so scrapping part of a material stack
     * leaves the rest untouched. Refunds a slice of the materials that went into the scrapped portion, if
     * it's a craftable item. */
    public function scrap(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'inventory_id' => ['required', 'exists:inventories,id'],
            'qty' => ['nullable', 'integer', 'min:1'],
        ]);

        $inventory = Inventory::where('id', $data['inventory_id'])
            ->where('character_id', $character->id)
            ->with('item')
            ->firstOrFail();

        $qty = min($data['qty'] ?? $inventory->qty, $inventory->qty);

        $refunded = $this->refundScrapMaterials($character, $inventory, $qty);
        $scrappedItem = $inventory->item;

        if ($qty >= $inventory->qty) {
            $inventory->delete();
        } else {
            $inventory->decrement('qty', $qty);
        }

        $this->deleteOrphanedCraftedVariant($scrappedItem);

        return response()->json([
            'inventory' => $character->inventory()->with('item')->get(),
            'refunded' => $refunded,
        ]);
    }

    /** Every crafted-variant Item row (rolled-stat gear, key "{baseKey}_crafted_{random}") exists solely to
     * back ONE specific Inventory row — nothing else ever references it (shop/wiki/recipes only ever point
     * at the base item). Once the last Inventory row holding it is scrapped, the row is permanently
     * unreachable dead weight, so delete it — this is what keeps `items` from growing forever as players
     * craft and scrap gear. Safe even if two stacks of the "same" crafted item existed, since we only ever
     * delete after confirming zero remaining Inventory references. */
    private function deleteOrphanedCraftedVariant(Item $item): void
    {
        if (! str_contains($item->key, '_crafted_')) {
            return;
        }

        if (Inventory::where('item_id', $item->id)->exists()) {
            return;
        }

        if (CraftingJob::where('result_item_id', $item->id)->exists()) {
            return;
        }

        $item->delete();
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

    private function refundScrapMaterials(Character $character, Inventory $inventory, int $qty): array
    {
        $recipe = Recipe::where('result_item_id', $this->baseItemFor($inventory->item)->id)->first();
        if (! $recipe) {
            return [];
        }

        $refunded = [];
        foreach ($recipe->materials_json as $material) {
            $refundQty = (int) ceil($material['qty'] * self::SCRAP_REFUND_PCT * $qty);
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
        if (isset($stats['atk_pct_buff'])) {
            $fights = $stats['buff_fights'] ?? 1;
            $character->atk_buff_pct = $stats['atk_pct_buff'];
            $character->atk_buff_fights_left = $fights;
            $applied[] = "+{$stats['atk_pct_buff']}% ATK for your next {$fights} fight".($fights > 1 ? 's' : '');
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
            'character' => $character->fresh(['attributes_', 'inventory.item', 'user']),
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
        $rolledPct = null;

        if ($success) {
            $rolledPct = $this->durability->rollRepairPct($outcome['repair_pct']);
            $restored = (int) round($gear->durability_max * $rolledPct / 100);
            $gear->update(['durability' => min($gear->durability_max, $gear->durability + $restored)]);
        }

        $pack->decrement('qty');
        if ($pack->fresh()->qty <= 0) {
            $pack->delete();
        }

        return response()->json([
            'success' => $success,
            'chance_pct' => $outcome['chance_pct'],
            'base_repair_pct' => $outcome['repair_pct'],
            'rolled_pct' => $rolledPct,
            'restored' => $restored,
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }

    /** Enchants an equipped-capable gear item up by one reforge level — see ReforgeService for the
     * cost/gate logic, which throws its own 422/403 responses via abort_if. */
    public function reforge(Request $request, Inventory $inventory)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $inventory->load('item');
        $gearRow = $this->reforge->reforge($character, $inventory);

        return response()->json([
            'inventory_row' => $gearRow,
            'character' => $character->fresh(),
        ]);
    }
}
