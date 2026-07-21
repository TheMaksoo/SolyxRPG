<?php

namespace App\Http\Controllers\Api;

use App\Models\Character;
use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\PvpRecord;
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

        $allRatings = $category === 'trophies' ? PvpRecord::pluck('rating')->all() : [];

        // Only the 'power' category calls effectiveStats(), which needs attributes_/inventory.item/pets to
        // avoid falling back to a per-character query for each — 'skills' was eager-loaded but never read
        // at all. The other five categories are plain column reads, so skip pulling every character's full
        // inventory+item rows for them; this endpoint loads every character in the game on every request.
        $ranked = Character::with([
                'user', 'pvpRecord', 'activeTitle', 'activeColor', 'activeBanner', 'activeIcon',
                ...($category === 'power' ? ['attributes_', 'inventory.item', 'pets.pet'] : []),
            ])
            ->get()
            ->map(fn (Character $c) => [
                'character_id' => $c->id,
                'name' => $c->name,
                'level' => $c->level,
                'base_class' => $c->base_class,
                'value' => $this->valueFor($c, $category),
                // Same hybrid tier-name+percentile rank shown on the PvP Arena page (PvpRecord::hybridRank),
                // so "Diamond" on the Trophies leaderboard means the same thing as "Diamond" in the arena
                // instead of the two screens using unrelated percentile-only vs tier-only labels. Named
                // trophy_rank (not "rank") because the final ->map() below already uses 'rank' for this
                // row's 1-100 leaderboard position — reusing that key would silently clobber this one.
                'trophy_rank' => $category === 'trophies' ? PvpRecord::hybridRank($c->pvpRecord->rating ?? 1000, $allRatings) : null,
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
