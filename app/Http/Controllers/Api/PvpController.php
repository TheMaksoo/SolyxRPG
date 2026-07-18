<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\PvpMatch;
use App\Models\PvpRecord;
use Illuminate\Http\Request;

class PvpController extends Controller
{
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

        return response()->json([
            'record' => $record,
            'rank' => $record->rank(),
            'opponents' => $opponents,
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

        $myPower = $character->effectiveStats()['power'];
        $oppPower = max(1, $opponent->effectiveStats()['power']);
        $winChance = max(0.1, min(0.9, $myPower / ($myPower + $oppPower)));
        $won = (mt_rand() / mt_getrandmax()) < $winChance;

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
            'created_at' => now(),
        ]);

        return response()->json([
            'result' => $won ? 'win' : 'loss',
            'rating_delta' => $delta,
            'record' => $myRecord->fresh(),
            'opponent' => $opponent->only(['id', 'name', 'base_class', 'level']),
        ]);
    }
}
