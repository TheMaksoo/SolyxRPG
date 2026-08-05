<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AutoBattleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutoBattleController extends Controller
{
    public function __construct(private AutoBattleService $autoBattle) {}

    public function show(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $summary = $this->autoBattle->tick($character);
        $character->refresh();

        $expiresAt = $character->auto_battle_expires_at;
        $secondsRemaining = $expiresAt
            ? max(0, $expiresAt->getTimestamp() - now()->getTimestamp())
            : 0;

        return response()->json([
            'active' => (bool) $expiresAt,
            'paused' => false,
            'expires_at' => $expiresAt,
            'seconds_remaining' => $secondsRemaining,
            'summary' => $summary,
            'costs' => $this->autoBattle->costs(),
            'pass_multiplier' => $character->user->vipPassMultiplier(),
            'granted_minutes' => collect($this->autoBattle->durations())->mapWithKeys(
                fn (int $m) => [$m => (int) round($m * $character->user->vipPassMultiplier())]
            ),
            'gems' => $character->user->gems,
        ]);
    }

    public function purchase(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'minutes' => ['required', 'integer', Rule::in($this->autoBattle->durations())],
        ]);

        try {
            $this->autoBattle->purchase($character, (int) $data['minutes']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $character->refresh();

        return response()->json([
            'gems' => $character->user->gems,
            'expires_at' => $character->auto_battle_expires_at,
            'seconds_remaining' => max(0, $character->auto_battle_expires_at->getTimestamp() - now()->getTimestamp()),
        ]);
    }
}
