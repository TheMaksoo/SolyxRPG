<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\ClassProgression;
use Illuminate\Http\Request;

class ClassProgressionController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $progressions = ClassProgression::where('base_class', $character->base_class)
            ->orderBy('tier')
            ->get()
            ->map(function (ClassProgression $p) {
                // Only t20 (spec_class) carries a real mechanical bonus today — see
                // Character::SUBCLASS_BONUS_TEXT. t40/t60 stay flavor-only until those tiers get real
                // effects, so bonus_text is simply absent for them rather than showing a fake number.
                $p->bonus_text = Character::SUBCLASS_BONUS_TEXT[$p->key] ?? null;

                return $p;
            });

        return response()->json(['progressions' => $progressions]);
    }
}
