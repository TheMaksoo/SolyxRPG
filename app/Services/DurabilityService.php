<?php

namespace App\Services;

use App\Models\GameConfig;

class DurabilityService
{
    /** Canonical grade order — index doubles as the tier-gap distance for repair penalties. */
    private const GRADE_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic'];

    /** Higher grade gear has more max durability. */
    private const MAX_DURABILITY_BY_RARITY = [
        'common' => 100,
        'rare' => 160,
        'epic' => 240,
        'legendary' => 350,
        'mythic' => 500,
    ];

    /** Durability lost per attack/hit-taken/gather action, regardless of grade. */
    public const DECAY_PER_ACTION = 1;

    /** Base repair %/chance% a pack grants when used on gear of its OWN grade. */
    private const REPAIR_PACK_TIERS = [
        'common' => ['repair_pct' => 20, 'chance_pct' => 70],
        'rare' => ['repair_pct' => 35, 'chance_pct' => 78],
        'epic' => ['repair_pct' => 55, 'chance_pct' => 85],
        'legendary' => ['repair_pct' => 75, 'chance_pct' => 92],
        'mythic' => ['repair_pct' => 100, 'chance_pct' => 98],
    ];

    /** Each grade tier a pack is below the item's grade costs this many percentage points off both repair% and chance%. */
    private const TIER_GAP_CHANCE_PENALTY = 20;
    private const TIER_GAP_REPAIR_PENALTY = 15;
    private const FLOOR_PCT = 5;

    public function maxDurability(string $rarity): int
    {
        $default = self::MAX_DURABILITY_BY_RARITY[$rarity] ?? self::MAX_DURABILITY_BY_RARITY['common'];

        return (int) GameConfig::number("durability_max_{$rarity}", $default);
    }

    /** Repair packs' own display stats — what they grant when used on gear of the same grade. */
    public function packMeta(string $packGrade): array
    {
        return self::REPAIR_PACK_TIERS[$packGrade] ?? self::REPAIR_PACK_TIERS['common'];
    }

    /**
     * The effective repair% (of max durability restored on success) and chance% of success for a given
     * pack grade used against a given item grade. A pack of equal-or-higher grade than the item works at
     * full strength; a lower-grade pack still works, but both its odds and its restore amount are docked
     * per grade of shortfall.
     */
    public function repairOutcome(string $packGrade, string $itemGrade): array
    {
        $base = self::REPAIR_PACK_TIERS[$packGrade] ?? self::REPAIR_PACK_TIERS['common'];
        $gap = max(0, $this->gradeIndex($itemGrade) - $this->gradeIndex($packGrade));

        return [
            'repair_pct' => max(self::FLOOR_PCT, $base['repair_pct'] - $gap * self::TIER_GAP_REPAIR_PENALTY),
            'chance_pct' => max(self::FLOOR_PCT, $base['chance_pct'] - $gap * self::TIER_GAP_CHANCE_PENALTY),
        ];
    }

    private function gradeIndex(string $grade): int
    {
        $index = array_search($grade, self::GRADE_ORDER, true);

        return $index === false ? 0 : $index;
    }
}
