<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\TamedCompanion;
use App\Models\TamedCompanionLog;
use Illuminate\Http\Request;

/** Tamed monster companions (see CombatService::attemptTame) — a separate feature from the catalog
 * Companions/Pets system (Pet/CharacterPet/PetController): these fight as their own combatant in
 * battle rather than granting a passive % bonus, so they have their own roster/slot rules and no
 * shared code with the catalog-pet flows. Both live under the same "Companions" nav tab client-side. */
class TamedCompanionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('pets', $request->user()), 403, 'Companions are not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $companions = TamedCompanion::where('character_id', $character->id)
            ->whereNull('archived_at')
            ->orderByDesc('active')
            ->orderByDesc('level')
            ->get()
            ->map(fn (TamedCompanion $c) => $this->present($c));

        $log = TamedCompanionLog::where('character_id', $character->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['tamed_companion_id', 'event', 'level', 'created_at'])
            ->map(function (TamedCompanionLog $entry) {
                // withTrashed-style lookup isn't needed — archived companions are never deleted, just
                // flagged, so this always resolves even for a 'died'/'freed' entry.
                $companion = TamedCompanion::find($entry->tamed_companion_id);

                return [
                    'name' => $companion?->name ?? 'Unknown companion',
                    'glyph' => $companion?->glyph ?? '❓',
                    'event' => $entry->event,
                    'level' => $entry->level,
                    'created_at' => $entry->created_at,
                ];
            });

        return response()->json([
            'companions' => $companions,
            'roster_cap' => $request->user()->tameRosterCap(),
            'active_slots' => $request->user()->activeTameSlots($character),
            'active_count' => $companions->where('active', true)->count(),
            'rename_cost_gems' => GameConfig::number('tame_companion_rename_cost_gems', 300),
            'revive_attempts_max' => (int) GameConfig::number('companion_revive_max_attempts', 2),
            'log' => $log,
        ]);
    }

    public function activate(Request $request, TamedCompanion $companion)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_unless($companion->character_id === $character->id, 404);
        abort_if($companion->isArchived(), 422, 'This companion is gone.');
        abort_if($companion->is_downed, 422, 'This companion is downed — revive it first.');

        if (! $companion->active) {
            $maxSlots = $request->user()->activeTameSlots($character);
            $activeCount = TamedCompanion::where('character_id', $character->id)->whereNull('archived_at')->where('active', true)->count();
            if ($activeCount >= $maxSlots) {
                return response()->json([
                    'message' => "You can only have {$maxSlots} companion".($maxSlots === 1 ? '' : 's')." active at once — bench one first.",
                ], 422);
            }
        }

        $companion->update(['active' => ! $companion->active]);

        return response()->json(['companion' => $this->present($companion->fresh())]);
    }

    /** Permanent — the companion is archived (not deleted, so the log stays intact) and immediately frees
     * a roster slot. There's no un-release; the frontend must confirm before calling this. */
    public function release(Request $request, TamedCompanion $companion)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_unless($companion->character_id === $character->id, 404);
        abort_if($companion->isArchived(), 422, 'This companion is already gone.');

        $companion->archive('freed');

        return response()->json(['message' => "{$companion->name} was set free."]);
    }

    /** Flat gem-cost rename, no free tier — same idea as ProfileController::rename() for characters,
     * just a separate (cheaper) tunable since this is a pet, not the player's own identity. */
    public function rename(Request $request, TamedCompanion $companion)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_unless($companion->character_id === $character->id, 404);
        abort_if($companion->isArchived(), 422, 'This companion is gone.');

        $data = $request->validate(['name' => ['required', 'string', 'max:30']]);

        $cost = GameConfig::number('tame_companion_rename_cost_gems', 300);
        if ($request->user()->gems < $cost) {
            return response()->json(['message' => 'Not enough gems.'], 422);
        }

        $request->user()->decrement('gems', $cost);
        GemLedger::log($request->user(), -$cost, 'tame_companion_rename', $character);
        $companion->update(['name' => $data['name']]);

        return response()->json(['companion' => $this->present($companion->fresh())]);
    }

    /** Grants a tamed companion XP from a pet_food item — the only way they level (unlike catalog pets,
     * which auto-gain a slice of battle XP). Mirrors InventoryController::use()'s ownership/qty pattern. */
    public function feed(Request $request, TamedCompanion $companion)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_unless($companion->character_id === $character->id, 404);
        abort_if($companion->isArchived(), 422, 'This companion is gone.');
        abort_if($companion->is_downed, 422, 'This companion is downed — revive it first.');

        $data = $request->validate(['item_id' => ['required', 'exists:items,id']]);

        $inventory = Inventory::where('character_id', $character->id)->where('item_id', $data['item_id'])->firstOrFail();
        abort_if($inventory->qty < 1, 422, 'Out of that item.');
        abort_if($inventory->item->type !== 'pet_food', 422, 'That item is not pet food.');

        $xpGain = (int) ($inventory->item->stat_json['pet_xp'] ?? 0);
        abort_if($xpGain <= 0, 422, 'That item has no feeding value.');

        $inventory->decrement('qty');
        if ($inventory->qty <= 0) {
            $inventory->delete();
        }

        $maxLevel = $companion->maxLevel();
        $xp = $companion->xp + $xpGain;
        $level = $companion->level;
        $leveledUp = false;

        while ($level < $maxLevel && $xp >= TamedCompanion::xpForLevel($level)) {
            $xp -= TamedCompanion::xpForLevel($level);
            $level++;
            $leveledUp = true;
        }
        if ($level >= $maxLevel) {
            $xp = 0;
        }

        // Leveling up raises effectiveHpMax — carry the same absolute HP forward rather than clamping
        // it, so feeding a companion never accidentally heals it back to full as a side effect.
        $companion->update(['xp' => $xp, 'level' => $level]);

        return response()->json([
            'companion' => $this->present($companion->fresh()),
            'leveled_up' => $leveledUp,
        ]);
    }

    /** Attempts to revive a downed companion (see CombatService::downCompanion) with a Pet Revive
     * Potion — chance is per-item (stat_json.revive_chance_pct), higher on the rarer recipe tiers. The
     * potion is spent on every attempt whether it succeeds or not — that's the "chance," not a
     * guaranteed use. A failed attempt counts against companion_revive_max_attempts (GameConfig,
     * default 2); once exhausted the companion is archived for good, same as any other death. */
    public function revive(Request $request, TamedCompanion $companion)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_unless($companion->character_id === $character->id, 404);
        abort_unless($companion->is_downed, 422, 'This companion is not downed.');

        $data = $request->validate(['item_id' => ['required', 'exists:items,id']]);

        $inventory = Inventory::where('character_id', $character->id)->where('item_id', $data['item_id'])->firstOrFail();
        abort_if($inventory->qty < 1, 422, 'Out of that item.');
        abort_if($inventory->item->type !== 'pet_revive_potion', 422, 'That item is not a revive potion.');

        $chance = (float) ($inventory->item->stat_json['revive_chance_pct'] ?? 0);

        $inventory->decrement('qty');
        if ($inventory->qty <= 0) {
            $inventory->delete();
        }

        $success = (mt_rand() / mt_getrandmax() * 100) < $chance;

        if ($success) {
            $hpPct = GameConfig::number('companion_revive_hp_pct', 50);
            $companion->is_downed = false;
            $companion->revive_attempts_used = 0;
            $companion->current_hp = max(1, (int) round($companion->effectiveHpMax() * $hpPct / 100));
            $companion->last_regen_at = now();
            $companion->save();
            TamedCompanionLog::log($companion, 'revived');

            return response()->json(['revived' => true, 'gone' => false, 'companion' => $this->present($companion->fresh())]);
        }

        $maxAttempts = (int) GameConfig::number('companion_revive_max_attempts', 2);
        $companion->increment('revive_attempts_used');

        if ($companion->revive_attempts_used >= $maxAttempts) {
            $companion->archive('died');

            return response()->json([
                'revived' => false,
                'gone' => true,
                'message' => "{$companion->name} couldn't be revived and is gone for good.",
            ]);
        }

        return response()->json(['revived' => false, 'gone' => false, 'companion' => $this->present($companion->fresh())]);
    }

    private function present(TamedCompanion $companion): array
    {
        return [
            'id' => $companion->id,
            'name' => $companion->name,
            'glyph' => $companion->glyph,
            'is_elite' => $companion->is_elite,
            'grade' => $companion->grade,
            'level' => $companion->level,
            'max_level' => $companion->maxLevel(),
            'xp' => $companion->xp,
            'xp_needed' => TamedCompanion::xpForLevel($companion->level),
            'active' => $companion->active,
            'current_hp' => $companion->current_hp,
            'effective_hp_max' => $companion->effectiveHpMax(),
            'effective_atk' => $companion->effectiveAtk(),
            'effective_def' => $companion->effectiveDef(),
            'is_downed' => $companion->is_downed,
            'revive_attempts_used' => $companion->revive_attempts_used,
        ];
    }
}
