<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CraftingController extends Controller
{
    public function index()
    {
        return response()->json(['recipes' => Recipe::where('enabled', true)->with('resultItem')->get()]);
    }

    public function craft(Request $request, Recipe $recipe)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        foreach ($recipe->materials_json as $material) {
            $owned = Inventory::where('character_id', $character->id)->where('item_id', $material['item_id'])->first();
            if (! $owned || $owned->qty < $material['qty']) {
                return response()->json(['message' => 'Missing required materials.'], 422);
            }
        }

        DB::transaction(function () use ($recipe, $character) {
            foreach ($recipe->materials_json as $material) {
                $owned = Inventory::where('character_id', $character->id)->where('item_id', $material['item_id'])->first();
                $owned->decrement('qty', $material['qty']);
                if ($owned->fresh()->qty <= 0) {
                    $owned->delete();
                }
            }

            $result = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $recipe->result_item_id, 'equipped' => false]);
            $result->qty = ($result->qty ?? 0) + 1;
            $result->save();
        });

        return response()->json(['inventory' => $character->inventory()->with('item')->get()]);
    }
}
