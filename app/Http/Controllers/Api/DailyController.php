<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyClaim;
use App\Models\GemLedger;
use App\Services\AchievementService;
use App\Services\BattlePassService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DailyController extends Controller
{
    /** The reward calendar repeats every 30 days rather than scaling forever. */
    private const CYCLE_LENGTH = 30;

    /** Every 7th day (and the day-30 finale) pays a gem bonus on top of the escalating gold. */
    private const MILESTONE_DAYS = [7, 14, 21, 28];

    public function __construct(
        private BattlePassService $battlePass = new BattlePassService(),
        private AchievementService $achievements = new AchievementService(),
    ) {
    }

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        return response()->json($this->buildResponse($character->dailyClaim));
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
        $cycleDay = (($streak - 1) % self::CYCLE_LENGTH) + 1;
        $reward = $this->rewardForDay($cycleDay);

        $character->increment('gold', $reward['gold']);
        if ($reward['gems']) {
            $character->increment('gems', $reward['gems']);
            GemLedger::log($character, $reward['gems'], "daily_reward:day{$cycleDay}");
        }

        $claim->update(['streak' => $streak, 'last_claim_date' => Carbon::today()]);
        $this->battlePass->addXp($character, 15);
        $this->achievements->check($character->fresh());

        return response()->json(array_merge(
            $this->buildResponse($claim->fresh()),
            ['character' => $character->fresh(), 'gold' => $reward['gold'], 'gems' => $reward['gems']],
        ));
    }

    /** This month's full reward calendar plus the character's progress through it, for the "what you get / what you can get" view. */
    private function buildResponse(?DailyClaim $claim): array
    {
        $streak = $claim->streak ?? 0;
        $claimedToday = $claim && $claim->last_claim_date && $claim->last_claim_date->isToday();
        $cycleDay = $streak === 0 ? 1 : ((($streak - 1) % self::CYCLE_LENGTH) + 1);

        $days = [];
        for ($day = 1; $day <= self::CYCLE_LENGTH; $day++) {
            $days[] = array_merge(['day' => $day], $this->rewardForDay($day), [
                'claimed' => $day < $cycleDay || ($day === $cycleDay && $claimedToday),
                'is_today' => $day === $cycleDay && ! $claimedToday,
            ]);
        }

        return [
            'streak' => $streak,
            'cycle_day' => $cycleDay,
            'cycle_length' => self::CYCLE_LENGTH,
            'last_claim_date' => $claim?->last_claim_date,
            'can_claim' => ! $claimedToday,
            'days' => $days,
        ];
    }

    /** Fixed reward for a given day (1-30) of the calendar — escalating gold, gem bonuses every 7th day, and a day-30 jackpot. */
    private function rewardForDay(int $day): array
    {
        $gold = 50 + ($day - 1) * 8;
        $milestoneIndex = array_search($day, self::MILESTONE_DAYS, true);

        if ($day === self::CYCLE_LENGTH) {
            return ['gold' => $gold + 300, 'gems' => 30];
        }

        return ['gold' => $gold, 'gems' => $milestoneIndex !== false ? 5 + $milestoneIndex * 3 : 0];
    }
}
