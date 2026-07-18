<?php

namespace App\Services;

class GradeService
{
    /** Rolled per-encounter tiers for "Walk" fights — trash monster definitions stay fixed, the grade scales them for that fight. */
    private const TIERS = [
        'common' => ['weight' => 65, 'unlock_level' => 1, 'hp_mult' => 1.0, 'atk_mult' => 1.0, 'reward_mult' => 1.0, 'label' => 'Common', 'color' => '#cbd5e1'],
        'elite' => ['weight' => 25, 'unlock_level' => 5, 'hp_mult' => 1.5, 'atk_mult' => 1.3, 'reward_mult' => 1.4, 'label' => 'Elite', 'color' => '#5cc7f5'],
        'champion' => ['weight' => 8, 'unlock_level' => 15, 'hp_mult' => 2.5, 'atk_mult' => 1.8, 'reward_mult' => 2.0, 'label' => 'Champion', 'color' => '#a78bfa'],
        'legendary' => ['weight' => 2, 'unlock_level' => 30, 'hp_mult' => 4.0, 'atk_mult' => 2.5, 'reward_mult' => 3.0, 'label' => 'Legendary', 'color' => '#eab308'],
    ];

    /** Rolls a grade, excluding tiers the character's level hasn't unlocked yet (e.g. no Legendary encounters below level 30). */
    public function roll(int $characterLevel = 1): string
    {
        $available = array_filter(self::TIERS, fn ($tier) => $characterLevel >= $tier['unlock_level']);
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

    public function hpMult(string $grade): float
    {
        return self::TIERS[$grade]['hp_mult'] ?? 1.0;
    }

    public function atkMult(string $grade): float
    {
        return self::TIERS[$grade]['atk_mult'] ?? 1.0;
    }

    public function rewardMult(string $grade): float
    {
        return self::TIERS[$grade]['reward_mult'] ?? 1.0;
    }

    public function meta(string $grade): array
    {
        return self::TIERS[$grade] ?? self::TIERS['common'];
    }

    public function all(): array
    {
        return self::TIERS;
    }
}
