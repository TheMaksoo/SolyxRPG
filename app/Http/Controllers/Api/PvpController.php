<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
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

        return response()->json([
            'record' => $record,
            'rank' => PvpRecord::bracketFromRatings($record->rating, $allRatings),
            'opponents' => $opponents->map(fn ($row) => [...$row, 'bracket' => PvpRecord::bracketFromRatings($row['rating'], $allRatings)]),
            'history' => $history,
        ]);
    }

    public function findMatch(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $opponent = Character::where('id', '!=', $character->id)->inRandomOrder()->first();
        abort_if(! $opponent, 422, 'No opponents available yet.');

        return $this->resolveMatch($character, $opponent);
    }

    public function challenge(Request $request, Character $opponent)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->id === $opponent->id, 422, 'Cannot challenge yourself.');

        return $this->resolveMatch($character, $opponent);
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

        return response()->json([
            'result' => $won ? 'win' : 'loss',
            'rating_delta' => $delta,
            'log' => $sim['log'],
            'record' => $myRecord->fresh(),
            'opponent' => $opponent->only(['id', 'name', 'base_class', 'level']),
        ]);
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
        $dmg = (int) round($attacker['eff_atk'] * (0.8 + mt_rand() / mt_getrandmax() * 0.4));
        $crit = (mt_rand() / mt_getrandmax() * 100) < ($attacker['crit_chance'] ?? 18);
        if ($crit) {
            $dmg = (int) round($dmg * 1.8);
        }
        $dmg = max(5, $dmg - (int) round(($defender['eff_def'] ?? 0) * 0.4));

        return ['amount' => $dmg, 'crit' => $crit];
    }
}
