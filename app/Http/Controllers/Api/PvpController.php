<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\PvpMatch;
use App\Models\PvpRecord;
use App\Services\AchievementService;
use Illuminate\Http\Request;

class PvpController extends Controller
{
    public function __construct(
        private AchievementService $achievements = new AchievementService(),
    ) {
    }

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('pvp', $request->user()), 403, 'PvP Arena is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $record = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);

        $opponents = Character::where('id', '!=', $character->id)
            ->with('pvpRecord')
            ->limit(20)
            ->get()
            ->map(fn (Character $c) => [
                'character' => $c,
                'rating' => $c->pvpRecord->rating ?? 1000,
            ]);

        $history = PvpMatch::where('character_id', $character->id)
            ->with('opponent')
            ->latest('created_at')
            ->limit(10)
            ->get();

        $allRatings = PvpRecord::pluck('rating')->all();
        $tier = PvpRecord::tierFor($record->rating);
        $maxAttempts = 10 + $request->user()->vipPvpBonusAttempts();
        $attemptsUsed = ($character->pvp_attempts_reset_at && $character->pvp_attempts_reset_at->isFuture())
            ? $character->pvp_attempts_used
            : 0;

        return response()->json([
            'record' => $record,
            'rank' => PvpRecord::bracketFromRatings($record->rating, $allRatings),
            'tier' => $tier,
            'pvp_attempts_used' => $attemptsUsed,
            'pvp_attempts_max' => $maxAttempts,
            'tier_progress' => PvpRecord::tierProgress($record->rating),
            'tier_ladder' => array_map(fn ($t) => [
                'name' => $t['name'],
                'color' => $t['color'],
                'is_current' => $t['name'] === $tier['name'],
            ], PvpRecord::PVP_TIERS),
            'opponents' => $opponents->map(fn ($row) => [
                ...$row,
                'bracket' => PvpRecord::bracketFromRatings($row['rating'], $allRatings),
                'difficulty' => match (true) {
                    $row['rating'] > $record->rating + 75 => 'Hard',
                    $row['rating'] < $record->rating - 75 => 'Easy',
                    default => 'Medium',
                },
            ]),
            'history' => $history,
        ]);
    }

    public function findMatch(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $this->consumePvpAttempt($character);

        $opponent = Character::where('id', '!=', $character->id)->inRandomOrder()->first();
        abort_if(! $opponent, 422, 'No opponents available yet.');

        return $this->resolveMatch($character, $opponent);
    }

    public function challenge(Request $request, Character $opponent)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->id === $opponent->id, 422, 'Cannot challenge yourself.');

        $this->consumePvpAttempt($character);

        return $this->resolveMatch($character, $opponent);
    }

    /** Resets the daily attempt counter if the reset window has lapsed, enforces the VIP-scaled daily cap,
     * then consumes one attempt. Aborts with a 422 if the player is out of attempts for today. */
    private function consumePvpAttempt(Character $character): void
    {
        if (! $character->pvp_attempts_reset_at || $character->pvp_attempts_reset_at->isPast()) {
            $character->pvp_attempts_used = 0;
            $character->pvp_attempts_reset_at = now()->endOfDay();
        }

        $max = 10 + $character->user->vipPvpBonusAttempts();
        abort_if($character->pvp_attempts_used >= $max, 422, 'No PvP attempts remaining today. Resets at midnight.');

        $character->pvp_attempts_used++;
        $character->save();
    }

    private function resolveMatch(Character $character, Character $opponent): \Illuminate\Http\JsonResponse
    {
        $myRecord = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);
        $oppRecord = $opponent->pvpRecord()->firstOrCreate([], ['rating' => 1000]);

        $sim = $this->simulate($character, $opponent);
        $won = $sim['winner'] === 'a';

        $expected = 1 / (1 + 10 ** (($oppRecord->rating - $myRecord->rating) / 400));
        $delta = (int) round(32 * (($won ? 1 : 0) - $expected));

        $myRecord->update([
            'rating' => max(0, $myRecord->rating + $delta),
            'wins' => $myRecord->wins + ($won ? 1 : 0),
            'losses' => $myRecord->losses + ($won ? 0 : 1),
            'win_streak' => $won ? $myRecord->win_streak + 1 : 0,
        ]);
        $oppRecord->update([
            'rating' => max(0, $oppRecord->rating - $delta),
            'wins' => $oppRecord->wins + ($won ? 0 : 1),
            'losses' => $oppRecord->losses + ($won ? 1 : 0),
        ]);

        PvpMatch::create([
            'character_id' => $character->id,
            'opponent_id' => $opponent->id,
            'result' => $won ? 'win' : 'loss',
            'rating_delta' => $delta,
            'log_json' => $sim['log'],
            'created_at' => now(),
        ]);

        $this->achievements->check($character->fresh());

        $dailyReward = $won ? $this->grantDailyPvpRewardIfDue($character) : ['granted' => false, 'gold' => 0, 'gems' => 0];

        return response()->json([
            'result' => $won ? 'win' : 'loss',
            'rating_delta' => $delta,
            'log' => $sim['log'],
            'record' => $myRecord->fresh(),
            'opponent' => $opponent->only(['id', 'name', 'base_class', 'level']),
            'daily_reward_granted' => $dailyReward['granted'],
            'daily_reward_gold' => $dailyReward['gold'],
            'daily_reward_gems' => $dailyReward['gems'],
        ]);
    }

    /** Grants a once-per-calendar-day, tier-scaled gold/gem reward on a player's first PvP win of the day.
     * Gold = 200 * tier index (Bronze=1..Master=6), gems = 5 * tier index. */
    private function grantDailyPvpRewardIfDue(Character $character): array
    {
        if ($character->last_daily_reward_at && $character->last_daily_reward_at->isSameDay(now())) {
            return ['granted' => false, 'gold' => 0, 'gems' => 0];
        }

        $record = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);
        $tier = PvpRecord::tierFor($record->rating);
        $tierIndex = array_search($tier, PvpRecord::PVP_TIERS) + 1;

        $gold = 200 * $tierIndex;
        $gems = 5 * $tierIndex;

        $character->last_daily_reward_at = now();
        $character->gold += $gold;
        $character->gems += $gems;
        $character->save();
        GemLedger::log($character, $gems, 'pvp_daily_win_reward');

        return ['granted' => true, 'gold' => $gold, 'gems' => $gems];
    }

    /** Simulates a real multi-round fight (not a coin-flip) using both sides' effective combat stats. */
    private function simulate(Character $a, Character $b): array
    {
        $statsA = $a->effectiveStats();
        $statsB = $b->effectiveStats();
        $hpA = $statsA['eff_hp_max'];
        $hpB = $statsB['eff_hp_max'];
        $log = [];
        $round = 0;
        $maxRounds = 30;

        while ($hpA > 0 && $hpB > 0 && $round < $maxRounds) {
            $round++;

            $hitB = $this->rollDamage($statsA, $statsB);
            $hpB = max(0, $hpB - $hitB['amount']);
            $log[] = "{$a->name} hits {$b->name} for {$hitB['amount']}".($hitB['crit'] ? ' (Critical!)' : '').'.';
            if ($hpB <= 0) {
                break;
            }

            $hitA = $this->rollDamage($statsB, $statsA);
            $hpA = max(0, $hpA - $hitA['amount']);
            $log[] = "{$b->name} hits {$a->name} for {$hitA['amount']}".($hitA['crit'] ? ' (Critical!)' : '').'.';
        }

        $pctA = $hpA / max(1, $statsA['eff_hp_max']);
        $pctB = $hpB / max(1, $statsB['eff_hp_max']);
        $winner = match (true) {
            $hpB <= 0 && $hpA > 0 => 'a',
            $hpA <= 0 && $hpB > 0 => 'b',
            $pctA === $pctB => $statsA['power'] >= $statsB['power'] ? 'a' : 'b',
            default => $pctA > $pctB ? 'a' : 'b',
        };

        $log[] = $winner === 'a' ? "{$a->name} wins!" : "{$b->name} wins!";

        return ['winner' => $winner, 'log' => $log];
    }

    private function rollDamage(array $attacker, array $defender): array
    {
        $dmg = (int) round($attacker['eff_atk'] * (0.75 + mt_rand() / mt_getrandmax() * 0.3));
        $crit = (mt_rand() / mt_getrandmax() * 100) < ($attacker['crit_chance'] ?? 18);
        if ($crit) {
            $dmg = (int) round($dmg * 1.8);
        }
        $dmg = max(5, $dmg - (int) round(($defender['eff_def'] ?? 0) * 0.55));

        return ['amount' => $dmg, 'crit' => $crit];
    }
}
