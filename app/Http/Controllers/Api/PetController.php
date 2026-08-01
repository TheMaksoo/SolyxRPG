<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterPet;
use App\Models\FeatureFlag;
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
        abort_unless(FeatureFlag::gate('pets', $request->user()), 403, 'Companions are not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->pets()->with('pet')->get()->keyBy('pet_id');
        $maxActiveSlots = $request->user()->maxActivePetSlots($character);

        $pets = Pet::where('enabled', true)->orderBy('sort_order')->get()->map(function (Pet $pet) use ($owned) {
            $ownedPet = $owned->get($pet->id);
            $bonusMult = $ownedPet ? $ownedPet->bonusMultiplier() : 1.0;

            $bonuses = collect($pet->bonus_json ?? [])->map(fn ($value, $key) => [
                'label' => self::STAT_LABELS[$key] ?? $key,
                'pct' => round($value * $bonusMult, 1),
            ])->values();

            return [
                'pet' => $pet,
                'owned' => $owned->has($pet->id),
                'active' => $ownedPet?->active ?? false,
                'level' => $ownedPet?->level,
                'rank' => $ownedPet?->rank ?? 0,
                'max_rank' => CharacterPet::MAX_RANK,
                'xp' => $ownedPet?->xp,
                'xp_needed' => $ownedPet ? CharacterPet::xpForLevel($ownedPet->level) : null,
                'max_level' => $ownedPet?->maxLevel() ?? CharacterPet::BASE_MAX_LEVEL,
                'can_rank_up' => $ownedPet?->canRankUp() ?? false,
                'rank_up_cost' => $ownedPet && $ownedPet->rank < CharacterPet::MAX_RANK
                    ? ['gold' => $ownedPet->goldCostForNextRank(), 'gems' => $ownedPet->gemCostForNextRank()]
                    : null,
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

        if ($pet->unlock_gold !== null) {
            if ($character->gold < $pet->unlock_gold) {
                return response()->json(['message' => 'Not enough gold.'], 422);
            }
            $character->decrement('gold', $pet->unlock_gold);
        } elseif ($pet->unlock_gems !== null) {
            if ($character->user->gems < $pet->unlock_gems) {
                return response()->json(['message' => 'Not enough gems.'], 422);
            }
            $character->user->decrement('gems', $pet->unlock_gems);
            GemLedger::log($character->user, -$pet->unlock_gems, "pet_unlock:{$pet->key}", $character);
        } else {
            return response()->json(['message' => 'This companion cannot be unlocked.'], 422);
        }

        $character->pets()->create(['pet_id' => $pet->id]);
        $this->achievements->check($character->fresh());

        return response()->json(['character' => $character->fresh()]);
    }

    /** Resets a maxed-out pet's level back to 1 in exchange for a permanent bonus increase (see
     * CharacterPet::rankMultiplier) and a higher level cap — costs gold (always) plus a small gem top-up
     * for pets that were originally unlocked with gems, compounding 10% per rank already reached. */
    public function rankUp(Request $request, Pet $pet)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->pets()->where('pet_id', $pet->id)->with('pet')->firstOrFail();

        if (! $owned->canRankUp()) {
            return response()->json(['message' => "This companion must reach level {$owned->maxLevel()} before it can rank up."], 422);
        }

        $goldCost = $owned->goldCostForNextRank();
        $gemCost = $owned->gemCostForNextRank();

        if ($character->gold < $goldCost) {
            return response()->json(['message' => "Not enough gold — Rank Up costs {$goldCost} gold.".($gemCost > 0 ? " and {$gemCost} gems." : '')], 422);
        }
        if ($gemCost > 0 && $character->user->gems < $gemCost) {
            return response()->json(['message' => "Not enough gems — Rank Up costs {$gemCost} gems on top of {$goldCost} gold."], 422);
        }

        $character->decrement('gold', $goldCost);
        if ($gemCost > 0) {
            $character->user->decrement('gems', $gemCost);
            GemLedger::log($character->user, -$gemCost, "pet_rank_up:{$pet->key}", $character);
        }

        $owned->update(['rank' => $owned->rank + 1, 'level' => 1, 'xp' => 0]);

        return response()->json([
            'character' => $character->fresh(),
            'pet' => $owned->fresh(),
        ]);
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
