<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterPet;
use App\Models\GemLedger;
use App\Models\Pet;
use App\Services\AchievementService;
use Illuminate\Http\Request;

class PetController extends Controller
{
    public function __construct(
        private AchievementService $achievements = new AchievementService(),
    ) {
    }

    private const STAT_LABELS = [
        'atk_pct' => 'ATK',
        'def_pct' => 'DEF',
        'crit_pct' => 'Crit',
        'xp_pct' => 'XP',
        'gather_speed_pct' => 'Gather Speed',
        'craft_speed_pct' => 'Craft Speed',
    ];

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->pets()->with('pet')->get()->keyBy('pet_id');
        $maxActiveSlots = $request->user()->maxActivePetSlots($character);

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

        return response()->json([
            'pets' => $pets,
            'max_active_slots' => $maxActiveSlots,
            'active_count' => $owned->filter(fn (CharacterPet $p) => $p->active)->count(),
        ]);
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
        GemLedger::log($character, -$pet->unlock_gems, "pet_unlock:{$pet->key}");
        $character->pets()->create(['pet_id' => $pet->id]);
        $this->achievements->check($character->fresh());

        return response()->json(['character' => $character->fresh()]);
    }

    /** Toggles a pet active/inactive. Multiple pets can be active at once — how many depends on level and VIP
     * tier (see User::maxActivePetSlots). Activating past the cap is rejected rather than silently bumping
     * another pet, so the player chooses what to bench. */
    public function activate(Request $request, Pet $pet)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->pets()->where('pet_id', $pet->id)->firstOrFail();

        if (! $owned->active) {
            $maxSlots = $request->user()->maxActivePetSlots($character);
            $activeCount = $character->pets()->where('active', true)->count();
            if ($activeCount >= $maxSlots) {
                return response()->json([
                    'message' => "You can only have {$maxSlots} companion".($maxSlots === 1 ? '' : 's')." active at once — deactivate one first.",
                ], 422);
            }
        }

        $owned->update(['active' => ! $owned->active]);

        return response()->json(['pets' => $character->pets()->with('pet')->get()]);
    }
}
