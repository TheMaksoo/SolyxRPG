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

    /**
     * Every gathering/processing option per skill: which material it yields (or consumes+yields, for Smelting),
     * base qty, xp, energy cost, and the level it unlocks at. Mining, Woodchopping and Smelting all work the
     * same way — you pick which tier to work, and locked/unaffordable tiers are just disabled in the UI.
     */
    private const SKILLS = [
        'mining' => [
            'label' => 'Mining', 'glyph' => '⛏',
            'description' => 'Strike the rock for ore. Rank up to unlock richer veins.',
            'action_seconds' => 60,
            'targets' => [
                'stone' => ['label' => 'Stone', 'unlock_level' => 1, 'base_qty' => 3, 'xp' => 5, 'energy_cost' => 10],
                'iron_ore' => ['label' => 'Iron Ore', 'unlock_level' => 8, 'base_qty' => 2, 'xp' => 12, 'energy_cost' => 16],
                'silver_ore' => ['label' => 'Silver Ore', 'unlock_level' => 20, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24],
                'gold_ore' => ['label' => 'Gold Ore', 'unlock_level' => 35, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36],
                'mythril_ore' => ['label' => 'Mythril Ore', 'unlock_level' => 50, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50],
            ],
        ],
        'woodchopping' => [
            'label' => 'Woodchopping', 'glyph' => '🪓',
            'description' => 'Chop trees for lumber. Rank up to unlock rarer timber.',
            'action_seconds' => 60,
            'targets' => [
                'wood' => ['label' => 'Wood', 'unlock_level' => 1, 'base_qty' => 3, 'xp' => 5, 'energy_cost' => 10],
                'oak_wood' => ['label' => 'Oak Wood', 'unlock_level' => 8, 'base_qty' => 2, 'xp' => 12, 'energy_cost' => 16],
                'ironwood' => ['label' => 'Ironwood', 'unlock_level' => 20, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24],
                'elderwood' => ['label' => 'Elderwood', 'unlock_level' => 35, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36],
                'moonwood' => ['label' => 'Moonwood', 'unlock_level' => 50, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50],
            ],
        ],
        'foraging' => [
            'label' => 'Foraging', 'glyph' => '🌿',
            'description' => 'Forage for herbs — the raw stock behind every potion. Rank up to find rarer blooms.',
            'action_seconds' => 60,
            'targets' => [
                'herb' => ['label' => 'Herb', 'unlock_level' => 1, 'base_qty' => 3, 'xp' => 5, 'energy_cost' => 10],
                'sage_leaf' => ['label' => 'Sage Leaf', 'unlock_level' => 8, 'base_qty' => 2, 'xp' => 12, 'energy_cost' => 16],
                'moonpetal' => ['label' => 'Moonpetal', 'unlock_level' => 20, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24],
                'sunroot' => ['label' => 'Sunroot', 'unlock_level' => 35, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36],
                'phoenix_bloom' => ['label' => 'Phoenix Bloom', 'unlock_level' => 50, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50],
            ],
        ],
        // Smelting comes after the three raw-gathering skills since it processes their output (ore -> bars).
        'smelting' => [
            'label' => 'Smelting', 'glyph' => '🔥',
            'description' => 'Melt ore into bars at the forge. Requires ore in your inventory.',
            'action_seconds' => 60,
            'targets' => [
                'iron_bar' => ['label' => 'Iron Bar', 'input_key' => 'iron_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 12, 'energy_cost' => 16, 'unlock_level' => 1],
                'silver_bar' => ['label' => 'Silver Bar', 'input_key' => 'silver_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 25, 'energy_cost' => 24, 'unlock_level' => 15],
                'gold_bar' => ['label' => 'Gold Bar', 'input_key' => 'gold_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 45, 'energy_cost' => 36, 'unlock_level' => 30],
                'mythril_bar' => ['label' => 'Mythril Bar', 'input_key' => 'mythril_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 80, 'energy_cost' => 50, 'unlock_level' => 45],
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
     * Effective seconds for one gather/smelt action: rank and Trade Speed attribute points each shave time off
     * the base, down to a floor — "the trade skills upgrade each level, increasing yield and speed both."
     * An equipped tool (Pickaxe for Mining, Axe for Woodchopping) stacks a further % reduction on top.
     */
    public function actionSeconds(string $skillKey, int $level, int $tradeSpeedPoints = 0, float $toolSpeedPct = 0): int
    {
        $base = self::SKILLS[$skillKey]['action_seconds'] ?? 0;
        if ($base <= 0) {
            return 0;
        }

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
