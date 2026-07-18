<?php

namespace App\Services;

use App\Models\CharacterTradeSkill;

class TradeSkillService
{
    public const MAX_LEVEL = 60;

    /** Levels a newly-unlocked material tier takes to ramp from a sliver of a chance up to its full weight. */
    private const RAMP_LEVELS = 10;

    private const MIN_ACTION_SECONDS = 2;
    private const LEVEL_SPEED_RAMP = 10;
    private const LEVEL_SPEED_STEP_PCT = 5;
    private const LEVEL_SPEED_CAP_PCT = 50;
    private const ATTR_SPEED_PCT_PER_POINT = 2;
    private const ATTR_SPEED_CAP_PCT = 40;

    /**
     * Mining and Woodchopping roll a random material every gather, weighted and gated by trade-skill level —
     * low tiers are all you can find early on, rarer tiers become possible (then increasingly likely) as you rank up.
     * Smelting stays a deliberate ore->bar conversion (you pick what to smelt), and Crafting stays passive.
     */
    private const SKILLS = [
        'mining' => [
            'label' => 'Mining', 'glyph' => '⛏',
            'description' => 'Strike the rock for ore. Early swings mostly turn up plain stone — rank up to start finding real ore.',
            'action_seconds' => 6,
            'materials' => [
                'stone' => ['label' => 'Stone', 'unlock_level' => 1, 'weight' => 50, 'base_qty' => 3, 'xp' => 5],
                'iron_ore' => ['label' => 'Iron Ore', 'unlock_level' => 8, 'weight' => 30, 'base_qty' => 2, 'xp' => 12],
                'silver_ore' => ['label' => 'Silver Ore', 'unlock_level' => 20, 'weight' => 15, 'base_qty' => 1, 'xp' => 25],
                'gold_ore' => ['label' => 'Gold Ore', 'unlock_level' => 35, 'weight' => 8, 'base_qty' => 1, 'xp' => 45],
                'mythril_ore' => ['label' => 'Mythril Ore', 'unlock_level' => 50, 'weight' => 4, 'base_qty' => 1, 'xp' => 80],
            ],
        ],
        'woodchopping' => [
            'label' => 'Woodchopping', 'glyph' => '🪓',
            'description' => 'Chop trees for lumber. Early swings mostly turn up plain wood — rank up to start finding rarer timber.',
            'action_seconds' => 6,
            'materials' => [
                'wood' => ['label' => 'Wood', 'unlock_level' => 1, 'weight' => 50, 'base_qty' => 3, 'xp' => 5],
                'oak_wood' => ['label' => 'Oak Wood', 'unlock_level' => 8, 'weight' => 30, 'base_qty' => 2, 'xp' => 12],
                'ironwood' => ['label' => 'Ironwood', 'unlock_level' => 20, 'weight' => 15, 'base_qty' => 1, 'xp' => 25],
                'elderwood' => ['label' => 'Elderwood', 'unlock_level' => 35, 'weight' => 8, 'base_qty' => 1, 'xp' => 45],
                'moonwood' => ['label' => 'Moonwood', 'unlock_level' => 50, 'weight' => 4, 'base_qty' => 1, 'xp' => 80],
            ],
        ],
        'smelting' => [
            'label' => 'Smelting', 'glyph' => '🔥',
            'description' => 'Melt ore into bars at the forge. Requires ore in your inventory.',
            'action_seconds' => 8,
            'targets' => [
                'iron_bar' => ['label' => 'Iron Bar', 'input_key' => 'iron_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 12, 'unlock_level' => 1],
                'silver_bar' => ['label' => 'Silver Bar', 'input_key' => 'silver_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 25, 'unlock_level' => 15],
                'gold_bar' => ['label' => 'Gold Bar', 'input_key' => 'gold_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 45, 'unlock_level' => 30],
                'mythril_bar' => ['label' => 'Mythril Bar', 'input_key' => 'mythril_ore', 'input_qty' => 2, 'base_qty' => 1, 'xp' => 80, 'unlock_level' => 45],
            ],
        ],
        'crafting' => [
            'label' => 'Crafting', 'glyph' => '🔨',
            'description' => 'Improves with every item you craft — higher rank means better odds at rare-and-up crafts.',
            'action_seconds' => 0,
            'materials' => [],
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

    /** Every target this skill's current level has unlocked (Smelting only — a deliberate pick, not a roll). */
    public function unlockedTargets(string $skillKey, int $level): array
    {
        $targets = self::SKILLS[$skillKey]['targets'] ?? [];

        return array_filter($targets, fn ($t) => $level >= $t['unlock_level']);
    }

    /** A tier's current roll weight: 0 until unlocked, then ramping up to full weight over RAMP_LEVELS levels (instant for the base Lv.1 tier). */
    private function tierWeight(array $tier, int $level): float
    {
        if ($level < $tier['unlock_level']) {
            return 0;
        }
        if ($tier['unlock_level'] <= 1) {
            return $tier['weight'];
        }

        $progress = min(1, ($level - $tier['unlock_level'] + 1) / self::RAMP_LEVELS);

        return $tier['weight'] * $progress;
    }

    /** Rolls which material a Mining/Woodchopping gather turns up, weighted by rank-gated tier. */
    public function rollMaterial(string $skillKey, int $level): ?string
    {
        $materials = self::SKILLS[$skillKey]['materials'] ?? [];
        $weights = [];
        foreach ($materials as $key => $tier) {
            $w = $this->tierWeight($tier, $level);
            if ($w > 0) {
                $weights[$key] = max(1, (int) round($w * 100));
            }
        }

        if (! $weights) {
            return null;
        }

        $total = array_sum($weights);
        $roll = mt_rand(1, $total);
        $cumulative = 0;

        foreach ($weights as $key => $w) {
            $cumulative += $w;
            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** Odds (%) of each Mining/Woodchopping material tier at the given level — shown to the player so the roll isn't a black box. */
    public function materialOdds(string $skillKey, int $level): array
    {
        $materials = self::SKILLS[$skillKey]['materials'] ?? [];
        $weights = [];
        foreach ($materials as $key => $tier) {
            $weights[$key] = $this->tierWeight($tier, $level);
        }
        $total = array_sum($weights) ?: 1;

        $odds = [];
        foreach ($materials as $key => $tier) {
            $unlocked = $level >= $tier['unlock_level'];
            $odds[$key] = [
                'key' => $key,
                'label' => $tier['label'],
                'unlock_level' => $tier['unlock_level'],
                'unlocked' => $unlocked,
                'pct' => $unlocked ? round($weights[$key] / $total * 100, 1) : 0,
                'yield_qty' => $this->yieldQty($skillKey, $key, $level),
                'xp' => $tier['xp'],
            ];
        }

        return $odds;
    }

    /** How much of the material/target one action yields at the given level — +1 every 5 ranks. */
    public function yieldQty(string $skillKey, string $key, int $level): int
    {
        $meta = self::SKILLS[$skillKey] ?? [];
        $entry = $meta['materials'][$key] ?? $meta['targets'][$key] ?? null;

        return $entry ? $entry['base_qty'] + intdiv($level, 5) : 0;
    }

    /**
     * Effective seconds for one gather/smelt action: rank and Trade Speed attribute points each shave time off
     * the base, down to a floor — "the trade skills upgrade each level, increasing yield and speed both."
     */
    public function actionSeconds(string $skillKey, int $level, int $tradeSpeedPoints = 0): int
    {
        $base = self::SKILLS[$skillKey]['action_seconds'] ?? 0;
        if ($base <= 0) {
            return 0;
        }

        $levelPct = min(self::LEVEL_SPEED_CAP_PCT, intdiv($level, self::LEVEL_SPEED_RAMP) * self::LEVEL_SPEED_STEP_PCT);
        $attrPct = min(self::ATTR_SPEED_CAP_PCT, $tradeSpeedPoints * self::ATTR_SPEED_PCT_PER_POINT);
        $seconds = $base * (1 - $levelPct / 100) * (1 - $attrPct / 100);

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
