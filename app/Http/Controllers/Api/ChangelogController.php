<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Changelog;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only player-facing changelog list, managed via the GM Console's Content tab → Changelog
 * (same generic CRUD as items/monsters/known_bugs — see GmContentController). The most recently
 * published entry's version doubles as "what version is the game on" — see current().
 *
 * Visibility rules:
 *   player  — visible to everyone (all authenticated users)
 *   tester  — visible to testers + GMs
 *   gm      — visible to GMs only
 */
class ChangelogController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'entries' => Changelog::orderByDesc('published_at')
                ->where(fn (Builder $q) => $this->applyVisibility($q, $request))
                ->get(),
        ]);
    }

    public function current(Request $request)
    {
        $latest = Changelog::orderByDesc('published_at')
            ->where(fn (Builder $q) => $this->applyVisibility($q, $request))
            ->first();

        return response()->json(['version' => $latest?->version ?? 'dev']);
    }

    /** Restrict changelog rows to those the current user is allowed to see. */
    private function applyVisibility(Builder $query, Request $request): void
    {
        $user = $request->user();

        if (! $user || $user->isGm()) {
            // GMs see every entry (no filter needed).
            return;
        }

        if ($user->is_tester || $user->role === 'tester') {
            // Testers see player + tester entries.
            $query->whereIn('visibility', ['player', 'tester']);
            return;
        }

        // Regular players see only player-visible entries.
        $query->where('visibility', 'player');
    }
}
