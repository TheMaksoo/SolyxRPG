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
        'mining_speed' => 1,
        'chopping_speed' => 1,
        'smelting_speed' => 1,
        'crafting_speed' => 1,
        'foraging_speed' => 1,
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

    /** Total points ever spent reaching $value in $attr — the sum of costForNextPoint() for every point
     * already bought, closed-form rather than a per-point loop since $value can grow unbounded over a
     * character's lifetime. Used to refund attribute points on a full respec. */
    public function totalSpentFor(string $attr, int $value): int
    {
        if ($value <= 0) {
            return 0;
        }

        $base = self::BASE_COST[$attr] ?? 1;
        $fullTiers = intdiv($value, self::TIER_SIZE);
        $remainder = $value % self::TIER_SIZE;

        $tierSurcharge = self::TIER_SIZE * intdiv($fullTiers * ($fullTiers - 1), 2) + $remainder * $fullTiers;

        return $base * $value + $tierSurcharge;
    }

    /** Total points ever spent across every attribute — the full refund for a character's whole allocation. */
    public function totalSpentAll(CharacterAttribute $attr): int
    {
        $total = 0;
        foreach (array_keys(self::BASE_COST) as $key) {
            $total += $this->totalSpentFor($key, $attr->$key ?? 0);
        }

        return $total;
    }

    public function dodgeChance(int $dodgePoints, float $gearDodgePct = 0): float
    {
        return min(self::DODGE_CAP_PCT, $dodgePoints + $gearDodgePct);
    }
}
