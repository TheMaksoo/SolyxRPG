<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\Character;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Monster;

class AutoBattleService
{
    /** Gem-purchasable pass lengths, in minutes. */
    private const DURATIONS = [15, 30, 60];

    /** Assumed real-world seconds one simulated fight "costs" — walking to the next encounter plus a few rounds. */
    private const FIGHT_SLOT_SECONDS = 20;

    /** Safety cap on rounds within a single simulated fight, so a regen-heavy Elite/Boss can't loop forever. */
    private const MAX_ROUNDS_PER_FIGHT = 25;

    /** Safety cap on fights simulated in one tick() call — the 60-minute pass ceiling already keeps this well under this. */
    private const MAX_FIGHTS_PER_TICK = 400;

    public function __construct(
        private CombatService $combat = new CombatService(),
        private GradeService $grades = new GradeService(),
    ) {}

    public function durations(): array
    {
        return self::DURATIONS;
    }

    /** Gem prices scale so the 60-minute pass is the best per-minute value — $1 cash-equivalent (~70 gems at the
     * entry pack's ~68/$ rate); 30 min costs 60% of that, 15 min costs 30% less than the 30-minute price, so cost
     * per minute rises as duration shrinks. The 60-minute pass is also the only one with a real-money option
     * (see StoreController's `auto_battle_60` SKU). */
    public function costFor(int $minutes): int
    {
        $fallback = match ($minutes) {
            15 => 30, 30 => 42, 60 => 70, default => 0,
        };

        return (int) GameConfig::number("auto_battle_gem_cost_{$minutes}", $fallback);
    }

    public function costs(): array
    {
        return collect(self::DURATIONS)->mapWithKeys(fn (int $m) => [$m => $this->costFor($m)])->all();
    }

    /** Buys/extends an auto-battle pass. Stacks onto time already remaining rather than overwriting it. */
    public function purchase(Character $character, int $minutes): void
    {
        if (! in_array($minutes, self::DURATIONS, true)) {
            throw new \InvalidArgumentException('Invalid auto-battle duration.');
        }

        $cost = $this->costFor($minutes);
        if ($character->gems < $cost) {
            throw new \RuntimeException("Not enough gems — need {$cost}.");
        }

        $character->decrement('gems', $cost);
        GemLedger::log($character, -$cost, "auto_battle:{$minutes}min");
        $character->refresh();

        $this->extend($character, $minutes);
    }

    /** Extends/starts the pass by the given minutes, regardless of how it was paid for (gems here, real money via
     * StoreController's `auto_battle_60` SKU). Stacks onto time already remaining rather than overwriting it. */
    public function extend(Character $character, int $minutes): void
    {
        $isExtending = $character->auto_battle_expires_at && $character->auto_battle_expires_at->isFuture();
        $base = $isExtending ? $character->auto_battle_expires_at : now();

        $character->auto_battle_expires_at = $base->copy()->addMinutes($minutes);
        if (! $isExtending) {
            $character->auto_battle_last_tick_at = now();
        }
        $character->save();
    }

    /**
     * Lazily catches up on however many auto-fights fit in the elapsed real time since the last tick (or since
     * purchase), following the same lazy-tick-on-read pattern as Character's HP/energy regen. Called whenever
     * the character is read (e.g. the Battle page loads) rather than from any background job — this game has none.
     *
     * Returns a summary of what happened (for a "while you were away" notice), or null if nothing ran.
     */
    public function tick(Character $character): ?array
    {
        if (! $character->auto_battle_expires_at) {
            return null;
        }

        if (Battle::where('character_id', $character->id)->where('status', 'active')->exists()) {
            // A manual battle is running — freeze the pass entirely (remaining time is preserved, not spent)
            // rather than let it silently drain while the player is fighting by hand.
            if (! $character->auto_battle_paused_at) {
                $character->auto_battle_paused_at = now();
                $character->save();
            }

            return null;
        }

        if ($character->auto_battle_paused_at) {
            // Resuming from a pause: shift both the tick clock and the expiry forward by however long the
            // manual battle lasted, so the paused span costs the pass nothing.
            $pausedFor = now()->getTimestamp() - $character->auto_battle_paused_at->getTimestamp();
            if ($pausedFor > 0) {
                $character->auto_battle_last_tick_at = $character->auto_battle_last_tick_at?->copy()->addSeconds($pausedFor);
                $character->auto_battle_expires_at = $character->auto_battle_expires_at->copy()->addSeconds($pausedFor);
            }
            $character->auto_battle_paused_at = null;
            $character->save();
        }

        $now = now();
        $windowEnd = $now->lt($character->auto_battle_expires_at) ? $now : $character->auto_battle_expires_at->copy();
        $lastTick = $character->auto_battle_last_tick_at ?? $windowEnd->copy();
        $elapsed = max(0, $windowEnd->getTimestamp() - $lastTick->getTimestamp());
        $fightsToRun = min(self::MAX_FIGHTS_PER_TICK, intdiv($elapsed, self::FIGHT_SLOT_SECONDS));

        if ($fightsToRun <= 0) {
            $this->expireIfPast($character, $now);

            return null;
        }

        $totals = ['fights' => 0, 'wins' => 0, 'losses' => 0, 'fled' => 0, 'gold' => 0, 'xp' => 0, 'gems' => 0];

        for ($i = 0; $i < $fightsToRun; $i++) {
            $character->refresh();
            $this->fastForwardRegen($character, self::FIGHT_SLOT_SECONDS);

            $monster = $this->pickMonster($character);
            if (! $monster) {
                break;
            }

            $grade = $this->grades->roll($character->level);
            $battle = $this->combat->start($character, $monster, $grade);
            $outcome = $this->simulateFight($battle, $character);

            $totals['fights']++;
            $totals[$outcome['type']]++;
            $totals['gold'] += $outcome['gold'];
            $totals['xp'] += $outcome['xp'];
            $totals['gems'] += $outcome['gems'];
        }

        $character->refresh();
        $character->auto_battle_last_tick_at = $lastTick->copy()->addSeconds($totals['fights'] * self::FIGHT_SLOT_SECONDS);
        $this->expireIfPast($character, $now);
        $character->save();

        return $totals['fights'] > 0 ? $totals : null;
    }

    private function expireIfPast(Character $character, \Illuminate\Support\Carbon $now): void
    {
        if ($character->auto_battle_expires_at && $now->gte($character->auto_battle_expires_at)) {
            $character->auto_battle_expires_at = null;
        }
    }

    /** Backdates the regen timestamps by the given slot so Character's own regen math grants the trickle a real
     * fight-finding walk would have earned, without duplicating that math here. */
    private function fastForwardRegen(Character $character, int $seconds): void
    {
        foreach (['last_regen_at', 'last_mana_regen_at', 'last_energy_regen_at'] as $column) {
            if ($character->$column) {
                $character->$column = $character->$column->copy()->subSeconds($seconds);
            }
        }
        $character->applyPassiveRegen();
    }

    /** Normal trash only — no bosses or elites. Elite burst/multi-hit kits can drop a character from above the
     * 30% heal threshold straight to 0 in one hit, which is an unacceptable risk for an unattended pass. */
    private function pickMonster(Character $character): ?Monster
    {
        return Monster::query()
            ->where('enabled', true)
            ->where('is_boss', false)
            ->where('is_elite', false)
            ->when($character->current_zone_id, fn ($q) => $q->where('zone_id', $character->current_zone_id))
            ->where('min_level', '<=', $character->level + 10)
            ->inRandomOrder()
            ->first();
    }

    /**
     * Plays out one fight round-by-round: attacks normally, but at or below 30% HP drinks the best healing potion
     * on hand (or flees if it has none, rather than risk a death spiral across many unattended fights). There's no
     * "do nothing" action in this game's turn model, so there's no separate cautious band between 30-50% HP —
     * every round is either an attack or a heal.
     */
    private function simulateFight(Battle $battle, Character $character): array
    {
        $hpMax = max(1, $character->effectiveStats()['eff_hp_max']);

        for ($round = 0; $round < self::MAX_ROUNDS_PER_FIGHT; $round++) {
            $battle->refresh();
            if ($battle->status !== 'active') {
                break;
            }

            $hpPct = ($battle->character_hp / $hpMax) * 100;

            if ($hpPct <= 30) {
                $potion = $this->bestHealingPotion($character);
                $result = $potion
                    ? $this->combat->act($battle, $character->fresh(), 'item', null, $potion->item_id)
                    : $this->combat->flee($battle, $character->fresh());
            } else {
                $result = $this->combat->act($battle, $character->fresh(), 'attack');
            }

            if ($result['result']) {
                return $this->extractOutcome($result['result']);
            }

            $battle = $result['battle'];
        }

        $battle->refresh();
        if ($battle->status === 'active') {
            $fleeResult = $this->combat->flee($battle, $character->fresh());

            return $this->extractOutcome($fleeResult['result']);
        }

        return ['type' => 'fled', 'gold' => 0, 'xp' => 0, 'gems' => 0];
    }

    /** The highest-heal_hp_pct consumable currently owned, or null if out of healing items entirely. */
    private function bestHealingPotion(Character $character): ?Inventory
    {
        return Inventory::where('character_id', $character->id)
            ->where('qty', '>', 0)
            ->whereHas('item', fn ($q) => $q->where('type', 'consumable'))
            ->with('item')
            ->get()
            ->filter(fn (Inventory $row) => ($row->item->stat_json['heal_hp_pct'] ?? 0) > 0)
            ->sortByDesc(fn (Inventory $row) => $row->item->stat_json['heal_hp_pct'])
            ->first();
    }

    private function extractOutcome(array $result): array
    {
        return match ($result['outcome']) {
            'won' => ['type' => 'wins', 'gold' => $result['gold'], 'xp' => $result['xp'], 'gems' => $result['gems']],
            'lost' => ['type' => 'losses', 'gold' => 0, 'xp' => 0, 'gems' => 0],
            default => ['type' => 'fled', 'gold' => 0, 'xp' => 0, 'gems' => 0],
        };
    }
}
