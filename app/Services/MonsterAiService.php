<?php

namespace App\Services;

use App\Models\Monster;

class MonsterAiService
{
    /** Fallback kit for any monster seeded/created without its own skills_json — matches the old flat-attack behavior. */
    private const DEFAULT_KIT = [
        ['key' => 'basic_attack', 'name' => 'Attack', 'type' => 'attack', 'dmg_mult' => 1.0, 'hits' => 1, 'weight' => 100, 'cooldown' => 0],
    ];

    /**
     * Picks the monster's next ability for this turn, weighted among whatever's off cooldown, then advances
     * the cooldown clock. $cooldowns is the battle's persisted per-ability-key "turns remaining" map.
     *
     * @return array{0: array, 1: array} [chosen ability, updated cooldowns]
     */
    public function choose(Monster $monster, array $cooldowns): array
    {
        $kit = ! empty($monster->skills_json) ? $monster->skills_json : self::DEFAULT_KIT;

        foreach ($cooldowns as $key => $remaining) {
            $cooldowns[$key] = max(0, $remaining - 1);
        }

        $available = array_values(array_filter($kit, fn (array $a) => ($cooldowns[$a['key']] ?? 0) <= 0));
        if (! $available) {
            // Every ability happened to be on cooldown (shouldn't happen if a 0-cooldown attack exists) — fall
            // back to whatever has no cooldown at all, then to the universal default so the monster always acts.
            $available = array_values(array_filter($kit, fn (array $a) => ($a['cooldown'] ?? 0) === 0));
        }
        if (! $available) {
            $available = self::DEFAULT_KIT;
        }

        $total = array_sum(array_map(fn (array $a) => $a['weight'] ?? 1, $available));
        $roll = mt_rand(1, max(1, $total));
        $cumulative = 0;
        $chosen = $available[0];
        foreach ($available as $ability) {
            $cumulative += $ability['weight'] ?? 1;
            if ($roll <= $cumulative) {
                $chosen = $ability;
                break;
            }
        }

        if (($chosen['cooldown'] ?? 0) > 0) {
            $cooldowns[$chosen['key']] = $chosen['cooldown'];
        }

        return [$chosen, $cooldowns];
    }
}
