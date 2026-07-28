<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterTradeSkill;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;

class AutoGatherService
{
    /** Gem-purchasable pass lengths, in minutes — the price tiers match Auto-Attack's exactly. */
    private const DURATIONS = [15, 30, 60];

    /** Which equip-slot type boosts which gathering skill — mirrors TradeSkillController. */
    private const TOOL_TYPE_BY_SKILL = ['mining' => 'pickaxe', 'woodchopping' => 'axe', 'foraging' => 'sickle'];

    /** Auto-Gather grants double the real time of the equivalent Auto-Attack tier, at the same gem price —
     * it's a slower, no-risk convenience rather than a combat multiplier. */
    private const DURATION_MULTIPLIER = 2;

    /** Safety cap on gather actions simulated in one tick() call. */
    private const MAX_ACTIONS_PER_TICK = 2000;

    private const SPEED_ATTR_BY_SKILL = [
        'mining' => 'mining_speed', 'woodchopping' => 'chopping_speed',
        'smelting' => 'smelting_speed', 'foraging' => 'foraging_speed',
    ];

    public function __construct(private TradeSkillService $tradeSkills = new TradeSkillService()) {}

    public function durations(): array
    {
        return self::DURATIONS;
    }

    /** Same gem prices as Auto-Attack — tunable independently via its own GameConfig keys. */
    public function costFor(int $minutes): int
    {
        $fallback = match ($minutes) {
            15 => 35, 30 => 60, 60 => 100, default => 0,
        };

        return (int) GameConfig::number("auto_gather_gem_cost_{$minutes}", $fallback);
    }

    public function costs(): array
    {
        return collect(self::DURATIONS)->mapWithKeys(fn (int $m) => [$m => $this->costFor($m)])->all();
    }

    public function grantedMinutesFor(int $minutes): int
    {
        return $minutes * self::DURATION_MULTIPLIER;
    }

    /** Buys/extends an auto-gather pass for one skill+target. Switching to a different skill/target while one
     * is still running is rejected — finish it or let it expire first, so time is never silently forfeited. */
    public function purchase(Character $character, string $skillKey, string $targetKey, int $minutes): void
    {
        if (! in_array($minutes, self::DURATIONS, true)) {
            throw new \InvalidArgumentException('Invalid auto-gather duration.');
        }

        $meta = $this->tradeSkills->meta($skillKey);
        if (! $meta || ! isset($meta['targets'][$targetKey])) {
            throw new \InvalidArgumentException('Unknown skill or target.');
        }

        $row = CharacterTradeSkill::where('character_id', $character->id)->where('skill_key', $skillKey)->first();
        $level = $row->level ?? 1;
        $this->assertUnlocked($character, $meta, $meta['targets'][$targetKey], $level);

        $isActive = $character->auto_gather_expires_at && $character->auto_gather_expires_at->isFuture();
        if ($isActive && ($character->auto_gather_skill !== $skillKey || $character->auto_gather_target !== $targetKey)) {
            throw new \RuntimeException('You already have a different Auto-Gather running — wait for it to finish first.');
        }

        $cost = $this->costFor($minutes);
        if ($character->gems < $cost) {
            throw new \RuntimeException("Not enough gems — need {$cost}.");
        }

        $character->decrement('gems', $cost);
        GemLedger::log($character, -$cost, "auto_gather:{$skillKey}:{$minutes}min");
        $character->refresh();

        $this->extend($character, $skillKey, $targetKey, $minutes);
    }

    /** Re-targets an already-running pass to a different skill/target, without touching remaining time or
     * gems. Ticks first so any actions already completed under the old target are flushed/collected before
     * the switch, then updates skill/target in place — expires_at and last_tick_at are left untouched, so
     * the running pass's remaining minutes and the in-flight fractional-second progress both carry over. */
    public function switchTarget(Character $character, string $skillKey, string $targetKey): array
    {
        $this->tick($character);
        $character->refresh();

        if (! $character->auto_gather_expires_at || ! $character->auto_gather_expires_at->isFuture()) {
            throw new \RuntimeException('No Auto-Gather pass is currently running.');
        }

        $meta = $this->tradeSkills->meta($skillKey);
        if (! $meta || ! isset($meta['targets'][$targetKey])) {
            throw new \InvalidArgumentException('Unknown skill or target.');
        }

        $row = CharacterTradeSkill::where('character_id', $character->id)->where('skill_key', $skillKey)->first();
        $level = $row->level ?? 1;
        $this->assertUnlocked($character, $meta, $meta['targets'][$targetKey], $level);

        $character->auto_gather_skill = $skillKey;
        $character->auto_gather_target = $targetKey;
        $character->save();

        return [
            'skill' => $skillKey,
            'target' => $targetKey,
            'expires_at' => $character->auto_gather_expires_at,
        ];
    }

    /** Mirrors TradeSkillController's own unlock check — a target's own unlock_level plus, for targets
     * that carry one (Smelting's bars, gated on the matching ore's Mining level), the cross-skill
     * requirement too. Throws the same way a validation failure elsewhere in this service does. */
    private function assertUnlocked(Character $character, array $meta, array $target, int $level): void
    {
        if ($level < $target['unlock_level']) {
            throw new \RuntimeException("Requires {$meta['label']} level {$target['unlock_level']}.");
        }

        if (isset($target['requires_skill'])) {
            $otherLevel = CharacterTradeSkill::where('character_id', $character->id)
                ->where('skill_key', $target['requires_skill'])
                ->value('level') ?? 1;

            if ($otherLevel < $target['requires_skill_level']) {
                $otherLabel = $this->tradeSkills->meta($target['requires_skill'])['label'] ?? $target['requires_skill'];

                throw new \RuntimeException("Requires {$otherLabel} level {$target['requires_skill_level']}.");
            }
        }
    }

    private function extend(Character $character, string $skillKey, string $targetKey, int $minutes): void
    {
        $grantedMinutes = $this->grantedMinutesFor($minutes);
        $isExtending = $character->auto_gather_expires_at && $character->auto_gather_expires_at->isFuture();
        $base = $isExtending ? $character->auto_gather_expires_at : now();

        $character->auto_gather_skill = $skillKey;
        $character->auto_gather_target = $targetKey;
        $character->auto_gather_expires_at = $base->copy()->addMinutes($grantedMinutes);
        if (! $isExtending) {
            $character->auto_gather_last_tick_at = now();
        }
        $character->save();
    }

    /** Lazily catches up on however many gather actions fit in the elapsed real time since the last tick,
     * following the same lazy-tick-on-read pattern as Auto-Attack. Stops early if energy or (for Smelting)
     * input materials run out — the remaining pass time is simply left unspent, not refunded. */
    public function tick(Character $character): ?array
    {
        if (! $character->auto_gather_skill || ! $character->auto_gather_expires_at) {
            return null;
        }

        $skillKey = $character->auto_gather_skill;
        $targetKey = $character->auto_gather_target;
        $meta = $this->tradeSkills->meta($skillKey);
        $target = $meta['targets'][$targetKey] ?? null;

        $now = now();
        if (! $target) {
            $this->clearIfExpired($character, $now, true);

            return null;
        }

        $windowEnd = $now->lt($character->auto_gather_expires_at) ? $now : $character->auto_gather_expires_at->copy();
        $lastTick = $character->auto_gather_last_tick_at ?? $windowEnd->copy();

        $row = CharacterTradeSkill::firstOrCreate(
            ['character_id' => $character->id, 'skill_key' => $skillKey],
            ['level' => 1, 'xp' => 0]
        );
        $tool = $this->equippedToolBonus($character, $skillKey);
        $speedPoints = $character->attributes_?->{self::SPEED_ATTR_BY_SKILL[$skillKey] ?? ''} ?? 0;
        $petGatherSpeedPct = $character->effectiveStats()['pet_gather_speed_pct'] ?? 0;
        $actionSeconds = max(1, $this->tradeSkills->actionSeconds($skillKey, $targetKey, $row->level, $speedPoints, $tool['speed_pct'] + $petGatherSpeedPct));
        $luckBonusPct = $this->luckBonusPct($character);

        $elapsed = max(0, $windowEnd->getTimestamp() - $lastTick->getTimestamp());
        $actionsToRun = min(self::MAX_ACTIONS_PER_TICK, intdiv($elapsed, $actionSeconds));

        if ($actionsToRun <= 0) {
            $this->clearIfExpired($character, $now, false);

            return null;
        }

        $outputItem = Item::where('key', $targetKey)->first();
        $inputItem = isset($target['input_key']) ? Item::where('key', $target['input_key'])->first() : null;

        $totals = ['actions' => 0, 'qty' => 0, 'xp' => 0, 'leveled_up' => false];
        $stoppedReason = null;

        for ($i = 0; $i < $actionsToRun; $i++) {
            $character->refresh();
            $row->refresh();

            if ($character->energy < $target['energy_cost']) {
                $stoppedReason = 'energy';
                break;
            }

            $qty = $this->tradeSkills->yieldQty($skillKey, $targetKey, $row->level, $tool['yield_bonus'], $luckBonusPct);

            if ($inputItem) {
                $requiredQty = $target['input_qty'] * $qty;
                $owned = Inventory::where('character_id', $character->id)->where('item_id', $inputItem->id)->first();
                if (! $owned || $owned->qty < $requiredQty) {
                    $stoppedReason = 'materials';
                    break;
                }
                $owned->decrement('qty', $requiredQty);
                if ($owned->fresh()->qty <= 0) {
                    $owned->delete();
                }
            }

            $affected = Character::where('id', $character->id)
                ->where('energy', '>=', $target['energy_cost'])
                ->decrement('energy', $target['energy_cost']);

            if ($affected === 0) {
                // Race condition: energy was depleted by another request
                $stoppedReason = 'energy';
                break;
            }

            $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $outputItem->id, 'equipped' => false]);
            $inventory->qty = ($inventory->qty ?? 0) + $qty;
            $inventory->save();

            if ($this->tradeSkills->grantXp($row, $target['xp'])) {
                $totals['leveled_up'] = true;
            }

            $totals['actions']++;
            $totals['qty'] += $qty;
            $totals['xp'] += $target['xp'];
        }

        $character->refresh();
        $character->auto_gather_last_tick_at = $lastTick->copy()->addSeconds($totals['actions'] * $actionSeconds);
        $stoppedForGood = $this->clearIfExpired($character, $now, false);

        if ($stoppedReason && ! $stoppedForGood) {
            // Out of energy/materials mid-pass — end the pass now rather than let it silently idle to expiry.
            $character->auto_gather_skill = null;
            $character->auto_gather_target = null;
            $character->auto_gather_expires_at = null;
        }
        $character->save();

        if ($totals['actions'] <= 0) {
            return null;
        }

        return [
            'skill' => $skillKey,
            'skill_label' => $meta['label'],
            'target' => $targetKey,
            'target_label' => $target['label'],
            'actions' => $totals['actions'],
            'qty' => $totals['qty'],
            'xp' => $totals['xp'],
            'leveled_up' => $totals['leveled_up'],
            'stopped_reason' => $stoppedReason,
        ];
    }

    /** Reads the equipped Pickaxe/Axe/Sickle's gather_speed_pct and gather_yield_bonus stats — mirrors
     * TradeSkillController::equippedToolBonus() so auto-gather yields/speeds match manual gathering. */
    private function equippedToolBonus(Character $character, string $skillKey): array
    {
        $toolType = self::TOOL_TYPE_BY_SKILL[$skillKey] ?? null;
        $tool = $toolType
            ? Inventory::where('character_id', $character->id)
                ->where('equipped', true)
                ->whereHas('item', fn ($q) => $q->where('type', $toolType))
                ->with('item')
                ->first()
            : null;

        if ($tool && $tool->durability_max !== null && $tool->durability <= 0) {
            $tool = null; // broken tool contributes nothing until repaired
        }

        $stats = $tool?->item->stat_json ?? [];

        return [
            'speed_pct' => $stats['gather_speed_pct'] ?? 0,
            'yield_bonus' => $stats['gather_yield_bonus'] ?? 0,
        ];
    }

    /** Luck grants a small % bonus to every trade skill's yield — mirrors TradeSkillController::luckBonusPct(). */
    private function luckBonusPct(Character $character): float
    {
        $luck = (int) ($character->effectiveStats()['luck'] ?? 0);
        $factor = GameConfig::number('luck_gather_bonus_factor', 0.5);
        $cap = GameConfig::number('luck_gather_bonus_cap_pct', 50);

        return min($cap, $luck * $factor);
    }

    /** Clears the auto-gather fields once the pass has actually expired. Returns whether it did. */
    private function clearIfExpired(Character $character, \Illuminate\Support\Carbon $now, bool $save): bool
    {
        if ($character->auto_gather_expires_at && $now->gte($character->auto_gather_expires_at)) {
            $character->auto_gather_skill = null;
            $character->auto_gather_target = null;
            $character->auto_gather_expires_at = null;
            if ($save) {
                $character->save();
            }

            return true;
        }

        return false;
    }
}
