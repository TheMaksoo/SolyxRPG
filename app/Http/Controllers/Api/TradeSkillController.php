<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterTradeSkill;
use App\Models\FeatureFlag;
use App\Models\GameConfig;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\TradeSkillLog;
use App\Services\DurabilityService;
use App\Services\QuestService;
use App\Services\TradeSkillService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TradeSkillController extends Controller
{
    /** Which equip-slot type boosts which gathering skill — Minecraft-style Pickaxe/Axe. Smelting has no tool. */
    private const TOOL_TYPE_BY_SKILL = ['mining' => 'pickaxe', 'woodchopping' => 'axe', 'foraging' => 'sickle'];

    /** Which per-skill Speed attribute (split from the old single Trade Speed) applies to each skill. */
    private const SPEED_ATTR_BY_SKILL = [
        'mining' => 'mining_speed', 'woodchopping' => 'chopping_speed',
        'smelting' => 'smelting_speed', 'foraging' => 'foraging_speed',
    ];

    /** How many recent gather/smelt actions to keep and show per character. */
    private const LOG_KEEP = 50;
    private const LOG_SHOW = 15;

    /** Which lifetime "fun stat" counter column each gathering skill bumps on every action. */
    private const STAT_COLUMN_BY_SKILL = [
        'mining' => 'times_mined', 'woodchopping' => 'times_chopped',
        'smelting' => 'times_smelted', 'foraging' => 'times_foraged',
    ];

    public function __construct(
        private TradeSkillService $tradeSkills,
        private QuestService $quests = new QuestService(),
    ) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('trade_skills', $request->user()), 403, 'Gathering is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $character->applyPassiveRegen();

        $rows = $character->tradeSkills()->get()->keyBy('skill_key');
        $luckBonusPct = $this->luckBonusPct($character);
        $ownedByKey = $this->ownedQuantitiesByItemKey($character);
        $petGatherSpeedPct = $character->effectiveStats()['pet_gather_speed_pct'] ?? 0;

        $skills = collect($this->tradeSkills->all())->map(function (array $meta, string $key) use ($character, $rows, $luckBonusPct, $ownedByKey, $petGatherSpeedPct) {
            $row = $rows->get($key);
            $level = $row->level ?? 1;
            $xp = $row->xp ?? 0;
            $tool = $this->equippedToolBonus($character, $key);
            $speedPoints = $character->attributes_?->{self::SPEED_ATTR_BY_SKILL[$key] ?? ''} ?? 0;
            $actionSeconds = $this->tradeSkills->actionSeconds($key, $level, $speedPoints, $tool['speed_pct'] + $petGatherSpeedPct);

            $cooldownRemaining = 0;
            if ($row?->last_action_at && $actionSeconds > 0) {
                $cooldownRemaining = max(0, $actionSeconds - (now()->getTimestamp() - $row->last_action_at->getTimestamp()));
            }

            $targets = collect($meta['targets'])->map(function (array $t, string $targetKey) use ($key, $level, $tool, $luckBonusPct, $ownedByKey, $actionSeconds) {
                $yieldQty = $this->tradeSkills->yieldQty($key, $targetKey, $level, $tool['yield_bonus'], $luckBonusPct);
                $requiredInputQty = isset($t['input_key']) ? $t['input_qty'] * $yieldQty : null;
                $inputOwnedQty = isset($t['input_key']) ? ($ownedByKey[$t['input_key']] ?? 0) : null;

                return [
                    'key' => $targetKey,
                    'label' => $t['label'],
                    'unlock_level' => $t['unlock_level'],
                    'unlocked' => $level >= $t['unlock_level'],
                    'yield_qty' => $yieldQty,
                    'xp' => $t['xp'],
                    'energy_cost' => $t['energy_cost'],
                    'action_seconds' => $actionSeconds,
                    'input_key' => $t['input_key'] ?? null,
                    'input_qty' => $t['input_qty'] ?? null,
                    'owned_qty' => $ownedByKey[$targetKey] ?? 0,
                    'input_owned_qty' => $inputOwnedQty,
                    'required_input_qty' => $requiredInputQty,
                    'has_input' => $requiredInputQty === null || $inputOwnedQty >= $requiredInputQty,
                ];
            })->values();

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
                'last_action_target' => $row?->last_action_target,
                'targets' => $targets,
            ];
        })->values();

        return response()->json([
            'trade_skills' => $skills,
            'energy' => $character->energy,
            'energy_max' => $character->effectiveStats()['eff_energy_max'],
            'log' => $this->recentLog($character),
        ]);
    }

    public function gather(Request $request, string $skillKey)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        abort_unless(in_array($skillKey, ['mining', 'woodchopping', 'smelting', 'foraging'], true), 404);

        $character->applyPassiveRegen();

        $meta = $this->tradeSkills->meta($skillKey);
        $data = $request->validate(['target' => ['required', Rule::in(array_keys($meta['targets']))]]);
        $targetKey = $data['target'];
        $target = $meta['targets'][$targetKey];

        $row = CharacterTradeSkill::firstOrCreate(
            ['character_id' => $character->id, 'skill_key' => $skillKey],
            ['level' => 1, 'xp' => 0]
        );

        if ($row->level < $target['unlock_level']) {
            return response()->json(['message' => "Requires {$meta['label']} level {$target['unlock_level']}."], 422);
        }

        $tool = $this->equippedToolBonus($character, $skillKey);
        $speedPoints = $character->attributes_?->{self::SPEED_ATTR_BY_SKILL[$skillKey] ?? ''} ?? 0;
        $petGatherSpeedPct = $character->effectiveStats()['pet_gather_speed_pct'] ?? 0;
        $actionSeconds = $this->tradeSkills->actionSeconds($skillKey, $row->level, $speedPoints, $tool['speed_pct'] + $petGatherSpeedPct);

        if ($row->last_action_at && $actionSeconds > 0) {
            $remaining = $actionSeconds - (now()->getTimestamp() - $row->last_action_at->getTimestamp());
            if ($remaining > 0) {
                return response()->json(['message' => "Not ready yet — {$remaining}s remaining.", 'remaining' => $remaining], 422);
            }
        }

        $energyCost = $target['energy_cost'];
        if ($character->energy < $energyCost) {
            return response()->json(['message' => "Not enough energy — need {$energyCost}."], 422);
        }

        $luckBonusPct = $this->luckBonusPct($character);
        $qty = $this->tradeSkills->yieldQty($skillKey, $targetKey, $row->level, $tool['yield_bonus'], $luckBonusPct);

        if (isset($target['input_key'])) {
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
        }

        $outputItem = Item::where('key', $targetKey)->firstOrFail();
        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $outputItem->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + $qty;
        $inventory->save();

        $character->decrement('energy', $energyCost);
        $character->increment(self::STAT_COLUMN_BY_SKILL[$skillKey]);
        $this->decayEquippedTool($character, $skillKey);
        $this->quests->progress($character, 'materials_gathered');

        $row->last_action_at = now();
        $row->last_action_target = $targetKey;
        $row->save();
        $leveledUp = $this->tradeSkills->grantXp($row, $target['xp']);

        $this->logAction($character, $skillKey, $targetKey, $qty, $target['xp']);

        return response()->json([
            'trade_skill' => $row->fresh(),
            'gained' => ['item' => $outputItem->only(['key', 'name', 'glyph']), 'qty' => $qty, 'xp' => $target['xp']],
            'leveled_up' => $leveledUp,
            'energy' => $character->fresh()->energy,
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }

    /** Owned quantity of every material/target/input item across all trade skills, keyed by item key, in one query. */
    private function ownedQuantitiesByItemKey(Character $character): array
    {
        $keys = collect($this->tradeSkills->all())
            ->flatMap(fn (array $meta) => collect($meta['targets'])->flatMap(fn (array $t, string $targetKey) => array_filter([$targetKey, $t['input_key'] ?? null])))
            ->unique()
            ->values();

        return Inventory::query()
            ->where('character_id', $character->id)
            ->whereHas('item', fn ($q) => $q->whereIn('key', $keys))
            ->with('item:id,key')
            ->get()
            ->groupBy(fn (Inventory $row) => $row->item->key)
            ->map(fn ($rows) => (int) $rows->sum('qty'))
            ->all();
    }

    /** Reads the equipped Pickaxe/Axe's gather_speed_pct and gather_yield_bonus stats, or zero if nothing's equipped/relevant. */
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

    /** Wears down the equipped Pickaxe/Axe by one use. No-ops for Smelting (no tool) or unequipped/legacy rows. */
    private function decayEquippedTool(Character $character, string $skillKey): void
    {
        $toolType = self::TOOL_TYPE_BY_SKILL[$skillKey] ?? null;
        if (! $toolType) {
            return;
        }

        $tool = Inventory::where('character_id', $character->id)
            ->where('equipped', true)
            ->whereHas('item', fn ($q) => $q->where('type', $toolType))
            ->first();

        if ($tool && $tool->durability_max !== null) {
            $tool->update(['durability' => max(0, $tool->durability - DurabilityService::DECAY_PER_ACTION)]);
        }
    }

    /** Luck grants a small % bonus to every trade skill's yield, GM-tunable, capped so it stays a nice-to-have. */
    private function luckBonusPct(Character $character): float
    {
        $luck = (int) ($character->effectiveStats()['luck'] ?? 0);
        $factor = GameConfig::number('luck_gather_bonus_factor', 0.5);
        $cap = GameConfig::number('luck_gather_bonus_cap_pct', 50);

        return min($cap, $luck * $factor);
    }

    /** Appends one gather/smelt action to the character's trade skill log, trimmed to the most recent LOG_KEEP entries. */
    private function logAction(Character $character, string $skillKey, string $targetKey, int $qty, int $xp): void
    {
        TradeSkillLog::create([
            'character_id' => $character->id,
            'skill_key' => $skillKey,
            'target_key' => $targetKey,
            'qty' => $qty,
            'xp' => $xp,
            'created_at' => now(),
        ]);

        $staleIds = TradeSkillLog::where('character_id', $character->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip(self::LOG_KEEP)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            TradeSkillLog::whereIn('id', $staleIds)->delete();
        }
    }

    /** The most recent LOG_SHOW actions, enriched with the skill/item labels the frontend needs to render them. */
    private function recentLog(Character $character): array
    {
        $entries = TradeSkillLog::where('character_id', $character->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(self::LOG_SHOW)
            ->get();

        return $entries->map(function (TradeSkillLog $entry) {
            $meta = $this->tradeSkills->meta($entry->skill_key);
            $target = $meta['targets'][$entry->target_key] ?? null;

            return [
                'skill_key' => $entry->skill_key,
                'skill_label' => $meta['label'] ?? $entry->skill_key,
                'target_key' => $entry->target_key,
                'target_label' => $target['label'] ?? $entry->target_key,
                'qty' => $entry->qty,
                'xp' => $entry->xp,
                'created_at' => $entry->created_at,
            ];
        })->all();
    }
}
