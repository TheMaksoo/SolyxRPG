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
}
