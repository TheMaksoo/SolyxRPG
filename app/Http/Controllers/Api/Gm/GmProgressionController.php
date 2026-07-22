<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Character;

class GmProgressionController extends Controller
{
    /** Levels at which xpForLevel() gates that single level's cost to the running cumulative total —
     * kept here (not read from Character) since they describe the curve's shape for display purposes,
     * not gameplay logic; see Character::buildXpTable() for the actual gate. */
    private const WALL_LEVELS = [8, 20, 35, 50];

    private const MAX_LEVEL_SHOWN = 160;

    /** Read-only view of the live XP curve for the GM console's Progression tab — per-level cost for
     * levels 1-160 straight from Character::xpForLevel(), so tuning the formula there is reflected here
     * with no separate curve to keep in sync. */
    public function xpCurve()
    {
        $costs = [];
        for ($level = 1; $level <= self::MAX_LEVEL_SHOWN; $level++) {
            $costs[] = Character::xpForLevel($level);
        }

        return response()->json([
            'costs' => $costs,
            'walls' => self::WALL_LEVELS,
        ]);
    }
}
