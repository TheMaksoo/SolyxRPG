<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WikiEntry;
use Illuminate\Support\Facades\Cache;

class WikiController extends Controller
{
    /** key => [label, icon] */
    private const CATEGORIES = [
        'items' => ['Items', '⚔'],
        'monsters' => ['Monsters', '🐺'],
        'zones' => ['Zones & Map', '🗺'],
        'dungeons' => ['Dungeons', '🏰'],
        'events' => ['Events', '📅'],
        'classes' => ['Classes', '🎖'],
        'skills' => ['Skills', '✦'],
        'pets' => ['Companions', '🐾'],
    ];

    /** Wiki content is reference data — it only changes when WikiSyncService re-syncs entries from
     * item/monster/etc. seed data, not on any player action — so it's identical for every request until
     * the next sync. PreventApiCaching still forces every API response no-store for the browser (this
     * pass doesn't touch that global behavior), but there's no reason to rebuild + re-serialize the full
     * entry list from the DB on every single request either, so it's cached server-side for a few minutes. */
    public function index()
    {
        return response()->json(Cache::remember('wiki:index', 300, function () {
            $entries = WikiEntry::query()
                ->where('enabled', true)
                ->orderBy('category')
                ->orderBy('sort_order')
                ->get(['id', 'category', 'glyph', 'name', 'sub', 'rarity', 'description', 'stats']);

            $counts = $entries->countBy('category');

            $categories = collect(self::CATEGORIES)
                ->only($counts->keys())
                ->map(fn ($meta, $key) => [
                    'key' => $key,
                    'label' => $meta[0],
                    'icon' => $meta[1],
                    'count' => $counts[$key] ?? 0,
                ])
                ->values();

            return [
                'categories' => $categories,
                'entries' => $entries->map(fn ($e) => [
                    'id' => $e->id,
                    'category' => $e->category,
                    'g' => $e->glyph,
                    'name' => $e->name,
                    'sub' => $e->sub,
                    'rarity' => $e->rarity,
                    'desc' => $e->description,
                    'stats' => $e->stats,
                ]),
            ];
        }));
    }
}
