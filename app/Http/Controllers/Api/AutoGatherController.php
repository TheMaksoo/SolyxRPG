<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AutoGatherService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutoGatherController extends Controller
{
    public function __construct(private AutoGatherService $autoGather) {}

    public function show(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $summary = $this->autoGather->tick($character);
        $character->refresh();

        $expiresAt = $character->auto_gather_expires_at;

        return response()->json([
            'active' => (bool) $expiresAt,
            'skill' => $character->auto_gather_skill,
            'target' => $character->auto_gather_target,
            'expires_at' => $expiresAt,
            'seconds_remaining' => $expiresAt ? max(0, $expiresAt->getTimestamp() - now()->getTimestamp()) : 0,
            'summary' => $summary,
            'costs' => $this->autoGather->costs(),
            'granted_minutes' => collect($this->autoGather->durations())->mapWithKeys(
                fn (int $m) => [$m => $this->autoGather->grantedMinutesFor($m)]
            ),
            'gems' => $character->gems,
        ]);
    }

    public function purchase(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'skill' => ['required', Rule::in(['mining', 'woodchopping', 'foraging', 'smelting'])],
            'target' => ['required', 'string'],
            'minutes' => ['required', 'integer', Rule::in($this->autoGather->durations())],
        ]);

        try {
            $this->autoGather->purchase($character, $data['skill'], $data['target'], (int) $data['minutes']);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $character->refresh();

        return response()->json([
            'gems' => $character->gems,
            'skill' => $character->auto_gather_skill,
            'target' => $character->auto_gather_target,
            'expires_at' => $character->auto_gather_expires_at,
            'seconds_remaining' => max(0, $character->auto_gather_expires_at->getTimestamp() - now()->getTimestamp()),
        ]);
    }
}
