<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrateOpening;
use App\Models\Inventory;
use App\Services\CrateService;
use Illuminate\Http\Request;

class CrateController extends Controller
{
    public function __construct(private CrateService $crates) {}

    /** In-progress and ready-to-collect crate openings — already-collected ones aren't returned, the
     * granted rewards already landed in gold/gems/inventory. */
    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $openings = CrateOpening::where('character_id', $character->id)
            ->whereNull('collected_at')
            ->orderBy('completes_at')
            ->get()
            ->map(fn (CrateOpening $o) => [
                'id' => $o->id,
                'rarity' => $o->rarity,
                'completes_at' => $o->completes_at,
                'seconds_remaining' => max(0, $o->completes_at->getTimestamp() - now()->getTimestamp()),
                'is_ready' => $o->isReady(),
            ]);

        return response()->json([
            'openings' => $openings,
            'max_concurrent' => $this->crates->maxConcurrentUnlocks($character),
        ]);
    }

    public function open(Request $request, Inventory $inventory)
    {
        $character = $request->user()->character;
        abort_unless($character && $inventory->character_id === $character->id, 403, 'That crate belongs to a different character.');

        $opening = $this->crates->open($character, $inventory);

        return response()->json([
            'opening' => $opening,
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }

    public function collect(Request $request, CrateOpening $opening)
    {
        $character = $request->user()->character;
        abort_unless($character && $opening->character_id === $character->id, 403, 'That crate belongs to a different character.');
        abort_if($opening->collected_at !== null, 422, 'Already collected.');
        abort_unless($opening->isReady(), 422, 'This crate is still unlocking.');

        $rewards = $this->crates->collect($opening);

        return response()->json([
            'rewards' => $rewards,
            'character' => $character->fresh(),
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }
}
