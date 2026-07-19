<?php

namespace App\Http\Controllers\Api;

use App\Models\Character;
use App\Http\Controllers\Controller;

class LeaderboardController extends Controller
{
    public function index()
    {
        $ranked = Character::with(['attributes_', 'inventory.item', 'user', 'activeTitle', 'activeColor', 'activeBanner', 'activeIcon'])
            ->get()
            ->map(fn (Character $c) => [
                'character_id' => $c->id,
                'name' => $c->name,
                'level' => $c->level,
                'base_class' => $c->base_class,
                'power' => $c->effectiveStats()['power'],
                'vip_tier' => $c->user?->hasActiveVip() ? $c->user->vip_tier : 'none',
                'title' => $c->activeTitle?->value,
                'name_color' => $c->activeColor?->value,
                'banner' => $c->activeBanner?->value,
                'icon' => $c->activeIcon?->value,
            ])
            ->sortByDesc('power')
            ->take(100)
            ->values()
            ->map(function ($row, $index) {
                $row['rank'] = $index + 1;

                return $row;
            });

        return response()->json(['leaderboard' => $ranked]);
    }
}
