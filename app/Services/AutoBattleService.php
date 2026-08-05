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

    /** Gem prices scale so the 60-minute pass is the best per-minute value — ~100 gems (~€0.50 base rate);
     * 30 min costs proportionally more per minute, and 15 min is the worst value. */
    public function costFor(int $minutes): int
    {
        $fallback = match ($minutes) {
            15 => 35, 30 => 60, 60 => 100, default => 0,
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
        if ($character->user->gems < $cost) {
            throw new \RuntimeException("Not enough gems — need {$cost}.");
        }

        $character->user->decrement('gems', $cost);
        GemLedger::log($character->user, -$cost, "auto_battle:{$minutes}min", $character);
        $character->refresh();

        $this->extend($character, $minutes);
    }

    /** Extends/starts the pass by the given minutes, regardless of how it was paid for (gems or real money via
     * StoreController's `auto_battle_480` SKU). Stacks onto time already remaining rather than overwriting it. */
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
     * Runs regardless of whether the character is also mid manual-battle — it never pauses or credits back time,
     * so playing manually has zero effect on it. Each simulated fight is tagged `is_auto` on its Battle row (see
     * `simulateFight()`/tick loop below) so BattleController's "the active battle" lookups can ignore it and never
     * collide with — or get confused for — the player's own manual fight.
     *
     * Returns a summary of what happened (for a "while you were away" notice), or null if nothing ran.
     */
    public function tick(Character $character): ?array
    {
        if (! $character->auto_battle_expires_at) {
            return null;
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
            $battle->update(['is_auto' => true]);
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
            // Same level cap as BattleController::walk() — no forward tolerance, so an unattended pass
            // can't accidentally queue up a monster built for a much higher level than the character.
            ->where('min_level', '<=', $character->level)
            ->inRandomOrder()
            ->first();
    }

    /**
     * Auto-Battle is "Training" — fully risk-free. It uses the character's real effective stats to decide
     * win/loss and still grants real gold/xp/gem rewards on a win, but nothing a fight would normally cost
     * (HP, mana, potions, gear durability, or a loss's death penalty) is ever left persisted on the real
     * character. Snapshots everything a fight could touch, plays it out through the same CombatService used
     * by manual battles, then restores whatever a real fight would have spent.
     */
    private function simulateFight(Battle $battle, Character $character): array
    {
        $snapshot = $this->snapshotTrainingState($character);
        $outcome = $this->playFightRounds($battle, $character);
        $this->restoreTrainingState($character, $snapshot, $outcome['type'] === 'losses');

        return $outcome;
    }

    /** Captures every real character/inventory field a simulated fight could touch, so it can be undone after. */
    private function snapshotTrainingState(Character $character): array
    {
        $character->refresh();

        return [
            'hp' => $character->hp,
            'mana' => $character->mana,
            'atk_buff_pct' => $character->atk_buff_pct,
            'atk_buff_fights_left' => $character->atk_buff_fights_left,
            'gold' => $character->gold,
            'xp' => $character->xp,
            'level' => $character->level,
            'attribute_points' => $character->attribute_points,
            'skill_points' => $character->skill_points,
            'gear_durability' => Inventory::where('character_id', $character->id)
                ->where('equipped', true)
                ->whereHas('item', fn ($q) => $q->whereIn('type', ['weapon', 'armor']))
                ->pluck('durability', 'id'),
            'inventory_qty' => Inventory::where('character_id', $character->id)
                ->where('equipped', false)
                ->pluck('qty', 'item_id'),
        ];
    }

    /** Undoes HP/mana/buff/gear-durability/potion spend from the fight that just ran. When $revertProgress is
     * true (the fight was lost), also undoes the death penalty's gold/xp/level loss — a lost training bout
     * costs nothing, it just earns no reward. */
    private function restoreTrainingState(Character $character, array $snapshot, bool $revertProgress): void
    {
        $character->refresh();
        $character->hp = $snapshot['hp'];
        $character->mana = $snapshot['mana'];
        $character->atk_buff_pct = $snapshot['atk_buff_pct'];
        $character->atk_buff_fights_left = $snapshot['atk_buff_fights_left'];

        if ($revertProgress) {
            $character->gold = $snapshot['gold'];
            $character->xp = $snapshot['xp'];
            $character->level = $snapshot['level'];
            $character->attribute_points = $snapshot['attribute_points'];
            $character->skill_points = $snapshot['skill_points'];
        }

        $character->save();

        foreach ($snapshot['gear_durability'] as $inventoryId => $durability) {
            Inventory::where('id', $inventoryId)->update(['durability' => $durability]);
        }

        foreach ($snapshot['inventory_qty'] as $itemId => $qty) {
            Inventory::updateOrCreate(
                ['character_id' => $character->id, 'item_id' => $itemId, 'equipped' => false],
                ['qty' => $qty]
            );
        }

        // Delete any consumable rows the fight created that didn't exist beforehand (shouldn't normally happen).
        Inventory::where('character_id', $character->id)
            ->where('equipped', false)
            ->whereNotIn('item_id', array_keys($snapshot['inventory_qty']->all()))
            ->delete();
    }

    /**
     * Plays out one fight round-by-round: attacks normally, but at or below 30% HP drinks the best healing potion
     * on hand (or flees if it has none, rather than risk a death spiral across many unattended fights). There's no
     * "do nothing" action in this game's turn model, so there's no separate cautious band between 30-50% HP —
     * every round is either an attack or a heal.
     */
    private function playFightRounds(Battle $battle, Character $character): array
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
                if (! $potion) {
                    $fleeResult = $this->combat->flee($battle, $character->fresh());

                    return $this->extractOutcome($fleeResult['result']);
                }

                // Drinking a potion is a free action (CombatService::act() doesn't pass the turn for
                // 'item') — heal, then still attack this same round instead of burning a whole loop
                // iteration on the heal alone.
                $healResult = $this->combat->act($battle, $character->fresh(), 'item', null, $potion->item_id);
                if ($healResult['result']) {
                    return $this->extractOutcome($healResult['result']);
                }
                $battle = $healResult['battle'];
            }

            $result = $this->combat->act($battle, $character->fresh(), 'attack');

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

    /** The strongest HP-healing consumable currently owned, including flat and percent-based heals. */
    private function bestHealingPotion(Character $character): ?Inventory
    {
        return Inventory::where('character_id', $character->id)
            ->where('qty', '>', 0)
            ->whereHas('item', fn ($q) => $q->where('type', 'consumable'))
            ->with('item')
            ->get()
            ->filter(fn (Inventory $row) =>
                ($row->item->stat_json['heal_hp_pct'] ?? 0) > 0 ||
                ($row->item->stat_json['heal_hp_flat'] ?? 0) > 0
            )
            ->sortByDesc(fn (Inventory $row) =>
                (($row->item->stat_json['heal_hp_pct'] ?? 0) * 100000) +
                ($row->item->stat_json['heal_hp_flat'] ?? 0)
            )
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
