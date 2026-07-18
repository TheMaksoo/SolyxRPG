<?php

namespace App\Http\Controllers\Api;

use App\Models\Character;
use App\Http\Controllers\Controller;

class LeaderboardController extends Controller
{
    public function index()
    {
        $ranked = Character::with(['attributes_', 'inventory.item'])
            ->get()
            ->map(fn (Character $c) => [
                'character_id' => $c->id,
                'name' => $c->name,
                'level' => $c->level,
                'base_class' => $c->base_class,
                'power' => $c->effectiveStats()['power'],
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
