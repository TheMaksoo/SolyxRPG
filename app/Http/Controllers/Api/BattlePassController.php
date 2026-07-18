<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BattlePassController extends Controller
{
    private const SEASON = 'ashfall';
    private const PREMIUM_GEM_COST = 950;

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $pass = $character->battlePasses()->firstOrCreate(['season' => self::SEASON], ['tier' => 0, 'xp' => 0, 'premium' => false]);

        return response()->json(['battle_pass' => $pass, 'premium_gem_cost' => self::PREMIUM_GEM_COST]);
    }

    /** Direct gem purchase of the premium track — an alternative to routing through Stripe checkout. */
    public function unlock(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $pass = $character->battlePasses()->firstOrCreate(['season' => self::SEASON], ['tier' => 0, 'xp' => 0]);

        if ($pass->premium) {
            return response()->json(['message' => 'Already premium.'], 422);
        }
        if ($character->gems < self::PREMIUM_GEM_COST) {
            return response()->json(['message' => 'Not enough gems.'], 422);
        }

        $character->decrement('gems', self::PREMIUM_GEM_COST);
        $pass->update(['premium' => true]);

        return response()->json(['battle_pass' => $pass->fresh(), 'character' => $character->fresh()]);
    }
}
