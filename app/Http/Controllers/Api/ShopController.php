<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab');

        $items = Item::query()
            ->where('enabled', true)
            ->when($tab, fn ($q) => $q->where('type', $tab))
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
        }

        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + 1;
        $inventory->save();

        return response()->json(['character' => $character->fresh(), 'inventory' => $inventory]);
    }
}
