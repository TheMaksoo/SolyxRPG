<?php

namespace App\Http\Controllers\Api;

use App\Models\Character;
use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    /** category key => [label, value resolver]. 'value' is what the board is ranked and displayed by. */
    private const CATEGORIES = ['power', 'level', 'trophies', 'monsters_slain', 'gold', 'deaths'];

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('leaderboard', $request->user()), 403, 'The Leaderboard is not currently available.');

        $category = $request->query('category', 'power');
        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'power';
        }

        $ranked = Character::with(['attributes_', 'inventory.item', 'user', 'pvpRecord', 'activeTitle', 'activeColor', 'activeBanner', 'activeIcon'])
            ->get()
            ->map(fn (Character $c) => [
                'character_id' => $c->id,
                'name' => $c->name,
                'level' => $c->level,
                'base_class' => $c->base_class,
                'value' => $this->valueFor($c, $category),
                'vip_tier' => $c->user?->hasActiveVip() ? $c->user->vip_tier : 'none',
                'title' => $c->activeTitle?->value,
                'name_color' => $c->activeColor?->value,
                'banner' => $c->activeBanner?->value,
                'icon' => $c->activeIcon?->value,
            ])
            ->sortByDesc('value')
            ->take(100)
            ->values()
            ->map(function ($row, $index) {
                $row['rank'] = $index + 1;

                return $row;
            });

        return response()->json(['leaderboard' => $ranked, 'category' => $category]);
    }

    private function valueFor(Character $c, string $category): int
    {
        return match ($category) {
            'level' => $c->level,
            'trophies' => $c->pvpRecord->rating ?? 1000,
            'monsters_slain' => $c->battles_won,
            'gold' => $c->gold,
            'deaths' => $c->battles_lost,
            default => $c->effectiveStats()['power'],
        };
    }
}
