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
        'quests' => ['Quests', '🎯'],
        'recipes' => ['Recipes', '🛠'],
        'events' => ['Events', '📅'],
        'classes' => ['Classes', '🎖'],
        'skills' => ['Skills', '✦'],
        'pets' => ['Companions', '🐾'],
        'cosmetics' => ['Cosmetics', '🎨'],
        'status_effects' => ['Buffs & Debuffs', '💫'],
    ];

    /** Wiki content is reference data — it only changes when WikiSyncService re-syncs entries from
     * item/monster/etc. seed data, not on any player action — so it's identical for every request until
     * the next sync. GmContentController busts this key on every content create/update/delete, so a GM
     * edit shows up immediately rather than waiting out the 5-minute TTL. PreventApiCaching still forces
     * every API response no-store for the browser (this pass doesn't touch that global behavior). */
    public function index()
    {
        return response()->json(Cache::remember('wiki:index', 300, function () {
            $entries = WikiEntry::query()
                ->where('enabled', true)
                ->orderBy('category')
                ->orderBy('group_label')
                ->orderBy('sort_order')
                ->get(['id', 'category', 'group_label', 'glyph', 'image_path', 'artist_name', 'name', 'sub', 'rarity', 'description', 'stats', 'details']);

            $counts = $entries->countBy('category');

            $categories = collect(self::CATEGORIES)
                ->only($counts->keys())
                ->map(function ($meta, $key) use ($entries, $counts) {
                    $inCategory = $entries->where('category', $key);

                    return [
                        'key' => $key,
                        'label' => $meta[0],
                        'icon' => $meta[1],
                        'count' => $counts[$key] ?? 0,
                        'tallies' => [
                            ['label' => 'Entries', 'value' => $counts[$key] ?? 0],
                            ['label' => 'Rarities', 'value' => $inCategory->pluck('rarity')->unique()->count()],
                            ['label' => 'With Art', 'value' => $inCategory->whereNotNull('image_path')->count()],
                        ],
                    ];
                })
                ->values();

            return [
                // ->toArray(): config/cache.php sets 'serializable_classes' => false (blocks unserializing
                // any PHP object from cache, a security hardening default), so a cached Collection comes
                // back as __PHP_Incomplete_Class on every read after the first (works on a fresh cache miss
                // since that path never round-trips through serialize/unserialize, then breaks for the rest
                // of the 5-minute TTL) — caching plain arrays instead sidesteps the restriction entirely.
                'categories' => $categories->toArray(),
                'entries' => $entries->map(fn ($e) => [
                    'id' => $e->id,
                    'category' => $e->category,
                    'group' => $e->group_label,
                    'g' => $e->glyph,
                    'image' => $e->image_path,
                    'artist' => $e->artist_name,
                    'name' => $e->name,
                    'sub' => $e->sub,
                    'rarity' => $e->rarity,
                    'desc' => $e->description,
                    'stats' => $e->stats,
                    'details' => $e->details,
                ])->values()->toArray(),
            ];
        }));
    }
}
