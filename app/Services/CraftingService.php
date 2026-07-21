<?php

namespace App\Services;

class CraftingService
{
    /** Rarity tier rolled for every craft, gated by the character's Crafting trade-skill level — higher
     * rank unlocks a shot at better tiers. Unlock levels pushed back and weights tilted harder toward
     * common (was 55/25/13/5/2 unlocking at 1/8/20/35/50) so rare-and-up gear stays meaningfully harder
     * to roll even at high Crafting rank, instead of rare+ becoming easy the moment a tier unlocks. */
    private const TIERS = [
        'common' => ['weight' => 68, 'unlock_level' => 1, 'label' => 'Common', 'color' => '#cbd5e1'],
        'rare' => ['weight' => 17, 'unlock_level' => 12, 'label' => 'Rare', 'color' => '#5cc7f5'],
        'epic' => ['weight' => 8, 'unlock_level' => 28, 'label' => 'Epic', 'color' => '#a78bfa'],
        'legendary' => ['weight' => 5, 'unlock_level' => 45, 'label' => 'Legendary', 'color' => '#eab308'],
        'mythic' => ['weight' => 2, 'unlock_level' => 65, 'label' => 'Mythic', 'color' => '#f472b6'],
    ];

    /** Rolls the crafted item's rarity, excluding tiers the Crafting skill level hasn't unlocked yet. */
    public function roll(int $craftingLevel): string
    {
        $available = array_filter(self::TIERS, fn ($tier) => $craftingLevel >= $tier['unlock_level']);
        $total = array_sum(array_column($available, 'weight'));
        $roll = mt_rand(1, $total);
        $cumulative = 0;

        foreach ($available as $key => $tier) {
            $cumulative += $tier['weight'];
            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return 'common';
    }

    public function meta(string $rarity): array
    {
        return self::TIERS[$rarity] ?? self::TIERS['common'];
    }

    /** Odds (%) of each tier at the given Crafting level — shown to the player so the roll isn't a black box. */
    public function odds(int $craftingLevel): array
    {
        $available = array_filter(self::TIERS, fn ($tier) => $craftingLevel >= $tier['unlock_level']);
        $total = array_sum(array_column($available, 'weight'));

        $odds = [];
        foreach (self::TIERS as $key => $tier) {
            $odds[$key] = [
                'label' => $tier['label'],
                'color' => $tier['color'],
                'unlocked' => $craftingLevel >= $tier['unlock_level'],
                'unlock_level' => $tier['unlock_level'],
                'pct' => $craftingLevel >= $tier['unlock_level'] ? round($tier['weight'] / $total * 100, 1) : 0,
            ];
        }

        return $odds;
    }
}
