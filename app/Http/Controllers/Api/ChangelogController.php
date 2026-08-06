<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Changelog;

/**
 * Read-only player-facing changelog list, managed via the GM Console's Content tab → Changelog
 * (same generic CRUD as items/monsters/known_bugs — see GmContentController). The most recently
 * published entry's version doubles as "what version is the game on" — see current().
 */
class ChangelogController extends Controller
{
    public function index()
    {
        $user = request()->user();

        $allowed = ['player'];
        if ($user->isTester()) {
            $allowed[] = 'tester';
        }
        if ($user->isGm()) {
            $allowed[] = 'gm';
        }

        return response()->json([
            'entries' => Changelog::whereIn('visibility', $allowed)->orderByDesc('published_at')->get(),
        ]);
    }

    public function current()
    {
        $latest = Changelog::where('visibility', 'player')->orderByDesc('published_at')->first();

        return response()->json(['version' => $latest?->version ?? 'dev']);
    }
}
