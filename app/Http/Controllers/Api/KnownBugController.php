<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnownBug;

/**
 * Read-only player-facing list of known bugs a GM has already logged (managed via the GM Console's
 * Content tab → Known Bugs, same generic CRUD as items/monsters/etc — see GmContentController).
 * Point of this page: let a player check "is this already reported?" before filing a duplicate
 * support ticket through BugReportWidget.
 */
class KnownBugController extends Controller
{
    /** Newest-first within each status, but status itself ordered reported → investigating → fixed
     * → fixed bugs sink since they're the least actionable thing for a player to read about. */
    private const STATUS_ORDER = ['reported' => 0, 'investigating' => 1, 'fixed' => 2];

    public function index()
    {
        $bugs = KnownBug::orderByDesc('created_at')->get()
            ->sortBy(fn (KnownBug $b) => self::STATUS_ORDER[$b->status] ?? 1)
            ->values();

        return response()->json(['bugs' => $bugs]);
    }
}
