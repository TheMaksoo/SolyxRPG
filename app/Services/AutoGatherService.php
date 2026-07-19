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

    /** Same fallback gem prices as Auto-Attack's tiers — tunable independently via its own GameConfig keys. */
    public function costFor(int $minutes): int
    {
        $fallback = match ($minutes) {
            15 => 30, 30 => 42, 60 => 70, default => 0,
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
        if ($level < $meta['targets'][$targetKey]['unlock_level']) {
            throw new \RuntimeException("Requires {$meta['label']} level {$meta['targets'][$targetKey]['unlock_level']}.");
        }

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
        $speedPoints = $character->attributes_?->{self::SPEED_ATTR_BY_SKILL[$skillKey] ?? ''} ?? 0;
        $petGatherSpeedPct = $character->effectiveStats()['pet_gather_speed_pct'] ?? 0;
        $actionSeconds = max(1, $this->tradeSkills->actionSeconds($skillKey, $row->level, $speedPoints, $petGatherSpeedPct));

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

            $qty = $this->tradeSkills->yieldQty($skillKey, $targetKey, $row->level);

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

            $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $outputItem->id, 'equipped' => false]);
            $inventory->qty = ($inventory->qty ?? 0) + $qty;
            $inventory->save();

            $character->decrement('energy', $target['energy_cost']);

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
