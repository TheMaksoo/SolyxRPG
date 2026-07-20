<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Character::xpForLevel() switched from a steep exponential curve (~640M cumulative XP to reach
 * level 50) to a flat quadratic one, 150*level^2+350 (~6M cumulative XP to reach level 50 — see
 * 2026_08_01_000024's sibling comment in Character.php). The old rarity->min_level thresholds
 * (1/8/20/35/55) were tuned against that old steep curve, so they no longer land at a sensible
 * fraction of the new, much flatter leveling pace. Checked against cumulative XP under the new
 * curve (sum of 150*L^2+350 for L=1..n-1):
 *   level 6  ≈ 10,000 cumulative XP    (rare tier — a short, early grind)
 *   level 15 ≈ 191,250 cumulative XP   (epic tier — solid mid-game milestone)
 *   level 28 ≈ 1,048,950 cumulative XP (legendary tier — real investment)
 *   level 45 ≈ 4,420,900 cumulative XP (mythic tier — late-game capstone)
 * These keep the same relative spacing (each tier several times the XP of the last) while fitting
 * inside the new curve's much smaller total XP budget, instead of gating rare/epic gear behind a
 * grind that no longer matches how fast characters actually level up.
 */
return new class extends Migration
{
    private const LEVEL_BY_RARITY = [
        'common' => 1,
        'rare' => 6,
        'epic' => 15,
        'legendary' => 28,
        'mythic' => 45,
    ];

    public function up(): void
    {
        foreach (self::LEVEL_BY_RARITY as $rarity => $level) {
            DB::table('items')->where('rarity', $rarity)->update(['min_level' => $level]);
        }
    }

    public function down(): void
    {
        $previous = [
            'common' => 1,
            'rare' => 8,
            'epic' => 20,
            'legendary' => 35,
            'mythic' => 55,
        ];

        foreach ($previous as $rarity => $level) {
            DB::table('items')->where('rarity', $rarity)->update(['min_level' => $level]);
        }
    }
};
