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
        $pausedAt = $character->auto_battle_paused_at;
        $secondsRemaining = $expiresAt
            ? max(0, $expiresAt->getTimestamp() - ($pausedAt ?? now())->getTimestamp())
            : 0;

        return response()->json([
            'active' => (bool) $expiresAt,
            'paused' => (bool) $pausedAt,
            'expires_at' => $expiresAt,
            'seconds_remaining' => $secondsRemaining,
            'summary' => $summary,
            'costs' => $this->autoBattle->costs(),
            'gems' => $character->gems,
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
            'gems' => $character->gems,
            'expires_at' => $character->auto_battle_expires_at,
            'seconds_remaining' => max(0, $character->auto_battle_expires_at->getTimestamp() - now()->getTimestamp()),
        ]);
    }
}
