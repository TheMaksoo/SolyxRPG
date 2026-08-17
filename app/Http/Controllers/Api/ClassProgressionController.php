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

        $baseClass = $character->base_class;
        // GM-only read-only preview of another class's progression ladder (see SkillController::index()'s
        // matching override and SkillsPage.vue's class switcher) — never touches the real character.
        $previewClass = $request->query('class');
        if ($previewClass && $request->user()->isGm() && in_array($previewClass, ['warrior', 'mage', 'rogue', 'ranger'], true)) {
            $baseClass = $previewClass;
        }

        // orderBy('tier') would sort alphabetically ('t100' < 't20' lexicographically) now that tier
        // strings span different lengths across the 5-rung ladder — order by the numeric level_cap instead.
        $progressions = ClassProgression::where('base_class', $baseClass)
            ->orderBy('level_cap')
            ->get()
            ->map(function (ClassProgression $p) {
                // Every tier across the 5-rung ladder (t20/t50/t100/t150/t200) carries a real mechanical
                // bonus — see Character::BRANCH_BONUSES.
                $p->bonus_text = Character::branchBonusText($p->key);

                return $p;
            });

        return response()->json(['progressions' => $progressions]);
    }
}
