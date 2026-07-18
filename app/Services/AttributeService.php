<?php

namespace App\Services;

use App\Models\CharacterAttribute;

class AttributeService
{
    /** Attribute points required for the FIRST point in each stat. Cap/regen stats start pricier since they're the strongest levers. */
    private const BASE_COST = [
        'damage' => 1,
        'armor' => 1,
        'hp_cap' => 2,
        'hp_regen' => 2,
        'mana_cap' => 2,
        'mana_regen' => 2,
        'crit' => 1,
        'crit_damage' => 1,
        'luck' => 1,
        'dodge' => 1,
        'energy_cap' => 2,
        'energy_regen' => 2,
        'trade_speed' => 1,
    ];

    /** Every this many points already invested, the cost for the next point rises by 1. */
    private const TIER_SIZE = 10;

    /** 1 dodge point = 1% dodge chance, hard capped so it's never a guaranteed no-hit build. */
    private const DODGE_CAP_PCT = 50;

    /** Attribute points required to buy the NEXT point in $attr, given how many are already invested. */
    public function costForNextPoint(string $attr, int $currentValue): int
    {
        $base = self::BASE_COST[$attr] ?? 1;

        return $base + intdiv($currentValue, self::TIER_SIZE);
    }

    /** costForNextPoint() for every spendable attribute, keyed by attribute — what the UI shows on each "+" button. */
    public function allCosts(CharacterAttribute $attr): array
    {
        $costs = [];
        foreach (array_keys(self::BASE_COST) as $key) {
            $costs[$key] = $this->costForNextPoint($key, $attr->$key ?? 0);
        }

        return $costs;
    }

    public function dodgeChance(int $dodgePoints, float $gearDodgePct = 0): float
    {
        return min(self::DODGE_CAP_PCT, $dodgePoints + $gearDodgePct);
    }
}
