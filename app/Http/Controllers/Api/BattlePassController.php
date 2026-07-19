<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GemLedger;
use App\Services\BattlePassService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BattlePassController extends Controller
{
    /** Matches the $5.99 cash price at the entry gem pack's rate (340 gems / $4.99 ≈ 68/$ → $5.99 ≈ 400 gems). */
    public const PREMIUM_GEM_COST = 400;

    public function __construct(private BattlePassService $battlePass) {}

    public function index(Request $request)
    {
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
            'total_tiers' => BattlePassService::TOTAL_TIERS,
            'track' => $track,
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
        if ($character->gems < self::PREMIUM_GEM_COST) {
            return response()->json(['message' => 'Not enough gems.'], 422);
        }

        $character->decrement('gems', self::PREMIUM_GEM_COST);
        GemLedger::log($character, -self::PREMIUM_GEM_COST, 'battlepass_premium_unlock');
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
