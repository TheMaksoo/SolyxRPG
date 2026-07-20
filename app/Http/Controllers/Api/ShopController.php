<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;
use App\Services\DurabilityService;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /** Gear is never qty-stacked — each copy needs its own durability, so it always gets its own row. */
    private const GEAR_TYPES = ['weapon', 'armor', 'quiver', 'pickaxe', 'axe', 'sickle', 'hammer'];

    public function __construct(private DurabilityService $durability) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('shop', $request->user()), 403, 'The Shop is not currently available.');

        $tab = $request->query('tab');
        $character = $request->user()->character;

        $items = Item::query()
            ->where('enabled', true)
            ->when($tab, fn ($q) => $q->where('type', $tab))
            ->when(
                $character,
                fn ($q) => $q->where(fn ($q2) => $q2->whereNull('class_key')->orWhere('class_key', $character->base_class))
            )
            ->orderBy('name')
            ->get();

        return response()->json(['items' => $items]);
    }

    public function buy(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['item_id' => ['required', 'exists:items,id']]);
        $item = Item::findOrFail($data['item_id']);

        if ($item->class_key !== null && $item->class_key !== $character->base_class) {
            return response()->json(['message' => 'That item is not usable by your class.'], 422);
        }

        if ($character->level < $item->min_level) {
            return response()->json(['message' => "Requires level {$item->min_level}."], 422);
        }

        if ($item->price_gold === null && $item->price_gems === null) {
            return response()->json(['message' => 'This item is not purchasable.'], 422);
        }

        if ($item->price_gold !== null) {
            if ($character->gold < $item->price_gold) {
                return response()->json(['message' => 'Not enough gold.'], 422);
            }
            $character->decrement('gold', $item->price_gold);
        } else {
            if ($character->gems < $item->price_gems) {
                return response()->json(['message' => 'Not enough gems.'], 422);
            }
            $character->decrement('gems', $item->price_gems);
            GemLedger::log($character, -$item->price_gems, "shop_buy:{$item->key}");
        }

        if (in_array($item->type, self::GEAR_TYPES, true)) {
            $max = $this->durability->maxDurability($item->rarity);
            $inventory = Inventory::create([
                'character_id' => $character->id, 'item_id' => $item->id, 'qty' => 1, 'equipped' => false,
                'durability' => $max, 'durability_max' => $max,
            ]);
        } else {
            $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
            $inventory->qty = ($inventory->qty ?? 0) + 1;
            $inventory->save();
        }

        return response()->json(['character' => $character->fresh(), 'inventory' => $inventory]);
    }
}
