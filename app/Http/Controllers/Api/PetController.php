<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterPet;
use App\Models\Pet;
use Illuminate\Http\Request;

class PetController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->pets()->with('pet')->get()->keyBy('pet_id');

        $pets = Pet::where('enabled', true)->get()->map(fn (Pet $pet) => [
            'pet' => $pet,
            'owned' => $owned->has($pet->id),
            'active' => $owned->get($pet->id)?->active ?? false,
        ]);

        return response()->json(['pets' => $pets]);
    }

    public function unlock(Request $request, Pet $pet)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->pets()->where('pet_id', $pet->id)->exists()) {
            return response()->json(['message' => 'Already unlocked.'], 422);
        }
        if ($character->gems < $pet->unlock_gems) {
            return response()->json(['message' => 'Not enough gems.'], 422);
        }

        $character->decrement('gems', $pet->unlock_gems);
        $character->pets()->create(['pet_id' => $pet->id]);

        return response()->json(['character' => $character->fresh()]);
    }

    public function activate(Request $request, Pet $pet)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->pets()->where('pet_id', $pet->id)->firstOrFail();

        $character->pets()->update(['active' => false]);
        $owned->update(['active' => true]);

        return response()->json(['pets' => $character->pets()->with('pet')->get()]);
    }
}
