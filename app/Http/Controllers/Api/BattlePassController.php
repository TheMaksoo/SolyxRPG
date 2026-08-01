<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Services\BattlePassService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BattlePassController extends Controller
{
    /** 1000 gems is exactly what tier 100's premium gem reward pays back (see
     * BattlePassService::currencyForTier), so a gem-purchased pass earns back its own cost by tier 100. */
    public const PREMIUM_GEM_COST = 1000;
    public const PREMIUM_CASH_LABEL = '€4.99';

    public function __construct(private BattlePassService $battlePass) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('battle_pass', $request->user()), 403, 'The Battle Pass is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $pass = $this->battlePass->passFor($character);

        $track = collect(range(1, BattlePassService::TOTAL_TIERS))->map(fn (int $tier) => [
            'tier' => $tier,
            'xp_required' => $this->battlePass->xpForTier($tier),
            'free_reward' => $this->battlePass->rewardForTier($tier, false, $character),
            'premium_reward' => $this->battlePass->rewardForTier($tier, true, $character),
        ]);

        return response()->json([
            'battle_pass' => $pass,
            'premium_gem_cost' => self::PREMIUM_GEM_COST,
            'premium_cash_label' => self::PREMIUM_CASH_LABEL,
            'total_tiers' => BattlePassService::TOTAL_TIERS,
            'track' => $track,
            'vip_bp_xp_bonus_pct' => $request->user()->vipBattlePassXpBonusPct(),
            'points_income' => $this->battlePass->pointsIncomeSummary($character),
            'season_progress' => $this->battlePass->seasonProgress($pass),
        ]);
    }

    /** Direct gem purchase of the premium track — an alternative to routing through Stripe checkout. */
    public function unlock(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $pass = $this->battlePass->passFor($character);

        if ($pass->premium) {
            return response()->json(['message' => 'Already premium.'], 422);
        }
        if ($character->user->gems < self::PREMIUM_GEM_COST) {
            return response()->json(['message' => 'Not enough gems.'], 422);
        }

        $character->user->decrement('gems', self::PREMIUM_GEM_COST);
        GemLedger::log($character->user, -self::PREMIUM_GEM_COST, 'battlepass_premium_unlock', $character);
        $pass->update(['premium' => true]);

        return response()->json(['battle_pass' => $pass->fresh(), 'character' => $character->fresh()]);
    }

    public function claim(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'tier' => ['required', 'integer', 'min:1', 'max:'.BattlePassService::TOTAL_TIERS],
            'track' => ['required', Rule::in(['free', 'premium'])],
        ]);

        $result = $this->battlePass->claimTier($character, (int) $data['tier'], $data['track']);
        if (! $result) {
            return response()->json(['message' => 'Nothing to claim there.'], 422);
        }

        return response()->json([
            'reward' => $result,
            'battle_pass' => $this->battlePass->passFor($character->fresh()),
            'character' => $character->fresh(),
        ]);
    }

    public function claimAll(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $totals = $this->battlePass->claimAll($character);

        return response()->json([
            'totals' => $totals,
            'battle_pass' => $this->battlePass->passFor($character->fresh()),
            'character' => $character->fresh(),
        ]);
    }
}
