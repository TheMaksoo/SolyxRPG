<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
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
        if (! in_array($item->type, ['weapon', 'armor'], true)) {
            return response()->json(['message' => 'Only weapons and armor can be equipped.'], 422);
        }

        // unequip anything else of the same type first (one weapon, one armor slot)
        Inventory::where('character_id', $character->id)
            ->where('equipped', true)
            ->whereHas('item', fn ($q) => $q->where('type', $item->type))
            ->update(['equipped' => false]);

        $inventory->update(['equipped' => true]);

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }
}
