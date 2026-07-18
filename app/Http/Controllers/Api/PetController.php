<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterPet;
use App\Models\Pet;
use Illuminate\Http\Request;

class PetController extends Controller
{
    private const STAT_LABELS = [
        'atk_pct' => 'ATK',
        'def_pct' => 'DEF',
        'crit_pct' => 'Crit',
        'xp_pct' => 'XP',
    ];

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->pets()->with('pet')->get()->keyBy('pet_id');

        $pets = Pet::where('enabled', true)->get()->map(function (Pet $pet) use ($owned) {
            $ownedPet = $owned->get($pet->id);
            $levelMult = $ownedPet ? $ownedPet->levelMultiplier() : 1.0;

            $bonuses = collect($pet->bonus_json ?? [])->map(fn ($value, $key) => [
                'label' => self::STAT_LABELS[$key] ?? $key,
                'pct' => round($value * $levelMult, 1),
            ])->values();

            return [
                'pet' => $pet,
                'owned' => $owned->has($pet->id),
                'active' => $ownedPet?->active ?? false,
                'level' => $ownedPet?->level,
                'xp' => $ownedPet?->xp,
                'xp_needed' => $ownedPet ? CharacterPet::xpForLevel($ownedPet->level) : null,
                'max_level' => CharacterPet::MAX_LEVEL,
                'bonuses' => $bonuses,
            ];
        });

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
