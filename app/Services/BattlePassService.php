<?php

namespace App\Services;

use App\Models\Character;

class BattlePassService
{
    public const SEASON = 'ashfall';
    public const TOTAL_TIERS = 50;
    private const XP_PER_TIER = 100;

    /** Grants battle pass xp, processing any tier-ups and their rewards. Returns tiers gained + rewards. */
    public function addXp(Character $character, int $amount): array
    {
        if ($amount <= 0) {
            return ['tiers_gained' => [], 'gold' => 0, 'gems' => 0];
        }

        $pass = $character->battlePasses()->firstOrCreate(
            ['season' => self::SEASON],
            ['tier' => 0, 'xp' => 0, 'premium' => false]
        );

        $xp = $pass->xp + $amount;
        $tier = $pass->tier;
        $tiersGained = [];

        while ($tier < self::TOTAL_TIERS && $xp >= self::XP_PER_TIER) {
            $xp -= self::XP_PER_TIER;
            $tier++;
            $tiersGained[] = $tier;
        }
        if ($tier >= self::TOTAL_TIERS) {
            $xp = 0;
        }

        $goldReward = 0;
        $gemReward = 0;
        foreach ($tiersGained as $n) {
            $milestone = $n % 5 === 0;
            $goldReward += $milestone ? $n * 20 : $n * 5;
            if ($pass->premium) {
                if ($milestone) {
                    $gemReward += $n;
                } else {
                    $goldReward += $n * 10;
                }
            }
        }

        if ($goldReward) {
            $character->increment('gold', $goldReward);
        }
        if ($gemReward) {
            $character->increment('gems', $gemReward);
        }

        $pass->update(['tier' => $tier, 'xp' => $xp]);

        return ['tiers_gained' => $tiersGained, 'gold' => $goldReward, 'gems' => $gemReward];
    }
}
