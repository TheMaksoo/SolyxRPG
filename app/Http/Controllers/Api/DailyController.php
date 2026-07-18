<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BattlePassService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DailyController extends Controller
{
    public function __construct(private BattlePassService $battlePass = new BattlePassService()) {}

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $claim = $character->dailyClaim;

        return response()->json([
            'streak' => $claim->streak ?? 0,
            'last_claim_date' => $claim?->last_claim_date,
            'can_claim' => ! $claim?->last_claim_date || ! $claim->last_claim_date->isToday(),
        ]);
    }

    public function claim(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $claim = $character->dailyClaim()->firstOrCreate([], ['streak' => 0]);

        if ($claim->last_claim_date && $claim->last_claim_date->isToday()) {
            return response()->json(['message' => 'Already claimed today.'], 422);
        }

        $streak = $claim->last_claim_date && $claim->last_claim_date->isYesterday()
            ? $claim->streak + 1
            : 1;

        $gold = 50 + $streak * 10;
        $gems = $streak % 7 === 0 ? 5 : 0;

        $character->increment('gold', $gold);
        if ($gems) {
            $character->increment('gems', $gems);
        }

        $claim->update(['streak' => $streak, 'last_claim_date' => Carbon::today()]);
        $this->battlePass->addXp($character, 15);

        return response()->json(['character' => $character->fresh(), 'streak' => $streak, 'gold' => $gold, 'gems' => $gems]);
    }
}
