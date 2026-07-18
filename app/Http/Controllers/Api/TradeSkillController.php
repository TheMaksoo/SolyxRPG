<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterTradeSkill;
use App\Models\GameConfig;
use App\Models\Inventory;
use App\Models\Item;
use App\Services\TradeSkillService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TradeSkillController extends Controller
{
    /** Flat Energy cost per Mining/Woodchopping/Smelting action, GM-overridable. */
    private const ENERGY_COST_DEFAULT = 10;

    public function __construct(private TradeSkillService $tradeSkills) {}

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $character->applyPassiveRegen();

        $rows = $character->tradeSkills()->get()->keyBy('skill_key');
        $tradeSpeed = $character->attributes_?->trade_speed ?? 0;

        $skills = collect($this->tradeSkills->all())->map(function (array $meta, string $key) use ($rows, $tradeSpeed) {
            $row = $rows->get($key);
            $level = $row->level ?? 1;
            $xp = $row->xp ?? 0;
            $actionSeconds = $this->tradeSkills->actionSeconds($key, $level, $tradeSpeed);

            $cooldownRemaining = 0;
            if ($row?->last_action_at && $actionSeconds > 0) {
                $cooldownRemaining = max(0, $actionSeconds - (now()->getTimestamp() - $row->last_action_at->getTimestamp()));
            }

            $targets = collect($meta['targets'] ?? [])->map(fn (array $t, string $targetKey) => [
                'key' => $targetKey,
                'label' => $t['label'],
                'unlock_level' => $t['unlock_level'],
                'unlocked' => $level >= $t['unlock_level'],
                'yield_qty' => $this->tradeSkills->yieldQty($key, $targetKey, $level),
                'xp' => $t['xp'],
                'input_key' => $t['input_key'] ?? null,
                'input_qty' => $t['input_qty'] ?? null,
            ])->values();

            return [
                'key' => $key,
                'label' => $meta['label'],
                'glyph' => $meta['glyph'],
                'description' => $meta['description'],
                'action_seconds' => $actionSeconds,
                'level' => $level,
                'xp' => $xp,
                'xp_max' => $this->tradeSkills->xpForLevel($level),
                'cooldown_remaining' => $cooldownRemaining,
                'materials' => isset($meta['materials']) && $meta['materials'] ? array_values($this->tradeSkills->materialOdds($key, $level)) : [],
                'targets' => $targets,
            ];
        })->values();

        return response()->json([
            'trade_skills' => $skills,
            'energy' => $character->energy,
            'energy_max' => $character->effectiveStats()['eff_energy_max'],
            'energy_cost' => $this->energyCost(),
        ]);
    }

    public function gather(Request $request, string $skillKey)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        abort_unless(in_array($skillKey, ['mining', 'woodchopping', 'smelting'], true), 404);

        $character->applyPassiveRegen();

        $meta = $this->tradeSkills->meta($skillKey);
        $row = CharacterTradeSkill::firstOrCreate(
            ['character_id' => $character->id, 'skill_key' => $skillKey],
            ['level' => 1, 'xp' => 0]
        );

        $tradeSpeed = $character->attributes_?->trade_speed ?? 0;
        $actionSeconds = $this->tradeSkills->actionSeconds($skillKey, $row->level, $tradeSpeed);

        if ($row->last_action_at && $actionSeconds > 0) {
            $remaining = $actionSeconds - (now()->getTimestamp() - $row->last_action_at->getTimestamp());
            if ($remaining > 0) {
                return response()->json(['message' => "Not ready yet — {$remaining}s remaining.", 'remaining' => $remaining], 422);
            }
        }

        $energyCost = $this->energyCost();
        if ($character->energy < $energyCost) {
            return response()->json(['message' => "Not enough energy — need {$energyCost}."], 422);
        }

        if ($skillKey === 'smelting') {
            $data = $request->validate(['target' => ['required', Rule::in(array_keys($meta['targets']))]]);
            $targetKey = $data['target'];
            $target = $meta['targets'][$targetKey];

            if ($row->level < $target['unlock_level']) {
                return response()->json(['message' => "Requires {$meta['label']} level {$target['unlock_level']}."], 422);
            }

            $qty = $this->tradeSkills->yieldQty($skillKey, $targetKey, $row->level);

            $inputItem = Item::where('key', $target['input_key'])->firstOrFail();
            $owned = Inventory::where('character_id', $character->id)->where('item_id', $inputItem->id)->first();
            $requiredQty = $target['input_qty'] * $qty;
            if (! $owned || $owned->qty < $requiredQty) {
                return response()->json(['message' => "Not enough {$inputItem->name} — need {$requiredQty}."], 422);
            }
            $owned->decrement('qty', $requiredQty);
            if ($owned->fresh()->qty <= 0) {
                $owned->delete();
            }

            $gainedXp = $target['xp'];
        } else {
            $targetKey = $this->tradeSkills->rollMaterial($skillKey, $row->level);
            abort_if($targetKey === null, 500, 'No material available to gather.');

            $qty = $this->tradeSkills->yieldQty($skillKey, $targetKey, $row->level);
            $gainedXp = $meta['materials'][$targetKey]['xp'];
        }

        $outputItem = Item::where('key', $targetKey)->firstOrFail();
        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $outputItem->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + $qty;
        $inventory->save();

        $character->decrement('energy', $energyCost);

        $row->last_action_at = now();
        $row->save();
        $leveledUp = $this->tradeSkills->grantXp($row, $gainedXp);

        return response()->json([
            'trade_skill' => $row->fresh(),
            'gained' => ['item' => $outputItem->only(['key', 'name', 'glyph']), 'qty' => $qty, 'xp' => $gainedXp],
            'leveled_up' => $leveledUp,
            'energy' => $character->fresh()->energy,
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }

    private function energyCost(): int
    {
        return (int) round(GameConfig::number('trade_skill_energy_cost', self::ENERGY_COST_DEFAULT));
    }
}
