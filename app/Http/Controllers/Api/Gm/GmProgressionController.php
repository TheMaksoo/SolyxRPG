<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\BattlePassService;

class GmProgressionController extends Controller
{
    /** Levels at which xpForLevel() gates that single level's cost to the running cumulative total —
     * kept here (not read from Character) since they describe the curve's shape for display purposes,
     * not gameplay logic; see Character::buildXpTable() for the actual gate. */
    private const WALL_LEVELS = [8, 20, 35, 50];

    private const MAX_LEVEL_SHOWN = 250;

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

    /** Read-only view of the Battle Pass points curve and quest income for the GM console's Progression
     * tab — per-tier cost straight from BattlePassService::xpForTier() plus the same season-income
     * projection shown on the player-facing Battle Pass page, so tuning either stays visible here with no
     * separate numbers to keep in sync. */
    public function battlePassCurve(BattlePassService $battlePass)
    {
        $costs = [];
        for ($tier = 1; $tier <= BattlePassService::TOTAL_TIERS; $tier++) {
            $costs[] = $battlePass->xpForTier($tier);
        }

        return response()->json([
            'costs' => $costs,
            'total_tiers' => BattlePassService::TOTAL_TIERS,
            'total_to_max' => $battlePass->totalXpToMax(),
            'points_income' => $battlePass->pointsIncomeSummary(),
        ]);
    }
}
