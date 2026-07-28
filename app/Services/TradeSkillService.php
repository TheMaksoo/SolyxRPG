<?php

namespace App\Services;

use App\Models\CharacterTradeSkill;

class TradeSkillService
{
    public const MAX_LEVEL = 60;

    private const MIN_ACTION_SECONDS = 2;
    private const LEVEL_SPEED_RAMP = 10;
    private const LEVEL_SPEED_STEP_PCT = 5;
    private const LEVEL_SPEED_CAP_PCT = 50;
    private const ATTR_SPEED_PCT_PER_POINT = 2;
    private const ATTR_SPEED_CAP_PCT = 40;

    /** Extra % of action_seconds added per tier above the first — a level-50 mythril vein (tier 5) takes
     * noticeably longer per swing than a level-1 stone node (tier 1), on top of the flat base time, so the
     * higher tiers stay a real time investment even after level/attribute/tool speed reductions. */
    private const TIER_TIME_STEP_PCT = 80;

    /**
     * Every gathering/processing option per skill: which material it yields (or consumes+yields, for Smelting),
     * base qty, xp, energy cost, and the level it unlocks at. Mining, Woodchopping and Smelting all work the
     * same way — you pick which tier to work, and locked/unaffordable tiers are just disabled in the UI.
     */
    private const SKILLS = [
        'mining' => [
            'label' => 'Mining', 'glyph' => '⛏',
            'description' => 'Strike the rock for ore. Rank up to unlock richer veins.',
            'action_seconds' => 120,
            'targets' => [
                'stone' => ['label' => 'Stone', 'tier' => 1, 'unlock_level' => 1, 'base_qty' => 3, 'xp' => 5, 'energy_cost' => 10],
                'iron_ore' => ['label' => 'Iron Ore', 'tier' => 2, 'unlock_level' => 8, 'base_qty' => 2, 'xp' => 12, 'energy_cost' => 16],
                'silver_ore' => ['label' => 'Silver Ore', 'tier' => 3, 'unlock_level' => 20, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24],
                'gold_ore' => ['label' => 'Gold Ore', 'tier' => 4, 'unlock_level' => 35, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36],
                'mythril_ore' => ['label' => 'Mythril Ore', 'tier' => 5, 'unlock_level' => 50, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50],
            ],
        ],
        'woodchopping' => [
            'label' => 'Woodchopping', 'glyph' => '🪓',
            'description' => 'Chop trees for lumber. Rank up to unlock rarer timber.',
            'action_seconds' => 120,
            'targets' => [
                'wood' => ['label' => 'Wood', 'tier' => 1, 'unlock_level' => 1, 'base_qty' => 3, 'xp' => 5, 'energy_cost' => 10],
                'oak_wood' => ['label' => 'Oak Wood', 'tier' => 2, 'unlock_level' => 8, 'base_qty' => 2, 'xp' => 12, 'energy_cost' => 16],
                'ironwood' => ['label' => 'Ironwood', 'tier' => 3, 'unlock_level' => 20, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24],
                'elderwood' => ['label' => 'Elderwood', 'tier' => 4, 'unlock_level' => 35, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36],
                'moonwood' => ['label' => 'Moonwood', 'tier' => 5, 'unlock_level' => 50, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50],
            ],
        ],
        'foraging' => [
            'label' => 'Foraging', 'glyph' => '🌿',
            'description' => 'Forage for herbs — the raw stock behind every potion. Rank up to find rarer blooms.',
            'action_seconds' => 120,
            'targets' => [
                'herb' => ['label' => 'Herb', 'tier' => 1, 'unlock_level' => 1, 'base_qty' => 3, 'xp' => 5, 'energy_cost' => 10],
                'sage_leaf' => ['label' => 'Sage Leaf', 'tier' => 2, 'unlock_level' => 8, 'base_qty' => 2, 'xp' => 12, 'energy_cost' => 16],
                'moonpetal' => ['label' => 'Moonpetal', 'tier' => 3, 'unlock_level' => 20, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24],
                'sunroot' => ['label' => 'Sunroot', 'tier' => 4, 'unlock_level' => 35, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36],
                'phoenix_bloom' => ['label' => 'Phoenix Bloom', 'tier' => 5, 'unlock_level' => 50, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50],
            ],
        ],
        // Smelting comes after the three raw-gathering skills since it processes their output (ore -> bars).
        // Every tier is gated TWO ways: unlock_level is Smelting's own progression (starts at 1, like every
        // other skill's tier-1 target), and requires_skill/requires_skill_level additionally demands the
        // matching ore's own Mining level — Iron Bar needs Smelting level 1 (always available) AND Mining
        // level 8 (Iron Ore's own unlock), not Smelting level 8. Gating tier 1 on Smelting's OWN level ever
        // reaching 8 was a softlock: Smelting only gains xp/levels from smelting something, so if the very
        // first tier itself required Smelting level 8 to unlock, there was no legal way to ever unlock it.
        'smelting' => [
            'label' => 'Smelting', 'glyph' => '🔥',
            'description' => 'Melt ore into bars at the forge. Requires ore in your inventory.',
            'action_seconds' => 120,
            'targets' => [
                'iron_bar' => ['label' => 'Iron Bar', 'tier' => 1, 'input_key' => 'iron_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 12, 'energy_cost' => 16, 'unlock_level' => 1, 'requires_skill' => 'mining', 'requires_skill_level' => 8],
                'silver_bar' => ['label' => 'Silver Bar', 'tier' => 2, 'input_key' => 'silver_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24, 'unlock_level' => 15, 'requires_skill' => 'mining', 'requires_skill_level' => 20],
                'gold_bar' => ['label' => 'Gold Bar', 'tier' => 3, 'input_key' => 'gold_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36, 'unlock_level' => 30, 'requires_skill' => 'mining', 'requires_skill_level' => 35],
                'mythril_bar' => ['label' => 'Mythril Bar', 'tier' => 4, 'input_key' => 'mythril_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50, 'unlock_level' => 45, 'requires_skill' => 'mining', 'requires_skill_level' => 50],
            ],
        ],
        'crafting' => [
            'label' => 'Crafting', 'glyph' => '🔨',
            'description' => 'Improves with every item you craft — higher rank means better odds at rare-and-up crafts.',
            'action_seconds' => 0,
            'targets' => [],
        ],
    ];

    public function all(): array
    {
        return self::SKILLS;
    }

    public function meta(string $skillKey): ?array
    {
        return self::SKILLS[$skillKey] ?? null;
    }

    /** Every target this skill's current level has unlocked. */
    public function unlockedTargets(string $skillKey, int $level): array
    {
        $targets = self::SKILLS[$skillKey]['targets'] ?? [];

        return array_filter($targets, fn ($t) => $level >= $t['unlock_level']);
    }

    /** Whether $target is actually usable: its own unlock_level (checked against THIS skill's level) AND,
     * for targets that carry one (Smelting's bars, gated on the matching ore's own Mining level), the
     * extra cross-skill requirement. $otherSkillLevel is the character's level in $target['requires_skill']
     * — pass 1 (the default for a never-touched skill) when there's no row for it yet. */
    public function targetUnlocked(array $target, int $level, int $otherSkillLevel = 1): bool
    {
        if ($level < $target['unlock_level']) {
            return false;
        }

        return ! isset($target['requires_skill']) || $otherSkillLevel >= $target['requires_skill_level'];
    }

    /** How much of the target material one action yields at the given level — +1 every 5 ranks, plus any equipped tool's flat bonus and a Luck %. */
    public function yieldQty(string $skillKey, string $targetKey, int $level, int $toolYieldBonus = 0, float $luckBonusPct = 0): int
    {
        $target = self::SKILLS[$skillKey]['targets'][$targetKey] ?? null;
        if (! $target) {
            return 0;
        }

        $qty = $target['base_qty'] + intdiv($level, 5) + $toolYieldBonus;

        return max($qty, (int) round($qty * (1 + $luckBonusPct / 100)));
    }

    /** Flat Energy cost of one action against this target — pricier tiers cost more to attempt. */
    public function energyCost(string $skillKey, string $targetKey): int
    {
        return self::SKILLS[$skillKey]['targets'][$targetKey]['energy_cost'] ?? 0;
    }

    /**
     * Effective seconds for one gather/smelt action against a specific target: the tier of that target adds
     * a flat % on top of the skill's base time (higher tiers take longer, not just cost more energy), then
     * rank and Trade Speed attribute points each shave time off, down to a floor — "the trade skills upgrade
     * each level, increasing yield and speed both." An equipped tool (Pickaxe for Mining, Axe for
     * Woodchopping) stacks a further % reduction on top.
     */
    public function actionSeconds(string $skillKey, ?string $targetKey, int $level, int $tradeSpeedPoints = 0, float $toolSpeedPct = 0): int
    {
        $base = self::SKILLS[$skillKey]['action_seconds'] ?? 0;
        if ($base <= 0) {
            return 0;
        }

        $tier = $targetKey ? (self::SKILLS[$skillKey]['targets'][$targetKey]['tier'] ?? 1) : 1;
        $base *= 1 + (($tier - 1) * self::TIER_TIME_STEP_PCT) / 100;

        $levelPct = min(self::LEVEL_SPEED_CAP_PCT, intdiv($level, self::LEVEL_SPEED_RAMP) * self::LEVEL_SPEED_STEP_PCT);
        $attrPct = min(self::ATTR_SPEED_CAP_PCT, $tradeSpeedPoints * self::ATTR_SPEED_PCT_PER_POINT);
        $seconds = $base * (1 - $levelPct / 100) * (1 - $attrPct / 100) * (1 - $toolSpeedPct / 100);

        return max(self::MIN_ACTION_SECONDS, (int) round($seconds));
    }

    public function xpForLevel(int $level): int
    {
        $xp = 40;
        for ($i = 1; $i < $level; $i++) {
            $xp = (int) round($xp * 1.12);
        }

        return $xp;
    }

    /** Grants xp to a trade skill row, handling multi-level-ups (capped at MAX_LEVEL). Returns levels gained. */
    public function grantXp(CharacterTradeSkill $row, int $xpGain): int
    {
        $xp = $row->xp + $xpGain;
        $level = $row->level;
        $levelsGained = 0;

        $xpMax = $this->xpForLevel($level);
        while ($level < self::MAX_LEVEL && $xp >= $xpMax) {
            $xp -= $xpMax;
            $level++;
            $levelsGained++;
            $xpMax = $this->xpForLevel($level);
        }
        if ($level >= self::MAX_LEVEL) {
            $xp = 0;
        }

        $row->update(['xp' => $xp, 'level' => $level]);

        return $levelsGained;
    }
}
