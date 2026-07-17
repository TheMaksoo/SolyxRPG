<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WikiEntry;

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

    public function index()
    {
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

        return response()->json([
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
        ]);
    }
}
