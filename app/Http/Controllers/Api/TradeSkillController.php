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
            $speedPct = $tool['speed_pct'] + $petGatherSpeedPct;

            // The shared per-skill cooldown was set by whichever target was worked last, so its remaining
            // time must be computed off THAT target's own (tier-scaled) action_seconds, not a flat
            // skill-wide number — higher tiers take longer, and the cooldown has to reflect the tier that
            // was actually gathered.
            $lastActionSeconds = $row?->last_action_target
                ? $this->tradeSkills->actionSeconds($key, $row->last_action_target, $level, $speedPoints, $speedPct)
                : 0;
            $cooldownRemaining = 0;
            if ($row?->last_action_at && $lastActionSeconds > 0) {
                $cooldownRemaining = max(0, $lastActionSeconds - (now()->getTimestamp() - $row->last_action_at->getTimestamp()));
            }

            $targets = collect($meta['targets'])->map(function (array $t, string $targetKey) use ($key, $level, $tool, $luckBonusPct, $ownedByKey, $speedPoints, $speedPct, $rows) {
                $yieldQty = $this->tradeSkills->yieldQty($key, $targetKey, $level, $tool['yield_bonus'], $luckBonusPct);
                $requiredInputQty = isset($t['input_key']) ? $t['input_qty'] * $yieldQty : null;
                $inputOwnedQty = isset($t['input_key']) ? ($ownedByKey[$t['input_key']] ?? 0) : null;
                // Smelting's bars each also require a specific Mining level (see SKILLS['smelting']) on top
                // of Smelting's own unlock_level — look up the OTHER skill's row, not this one's.
                $otherSkillLevel = isset($t['requires_skill']) ? ($rows->get($t['requires_skill'])->level ?? 1) : 1;

                return [
                    'key' => $targetKey,
                    'label' => $t['label'],
                    'unlock_level' => $t['unlock_level'],
                    'unlocked' => $this->tradeSkills->targetUnlocked($t, $level, $otherSkillLevel),
                    'requires_skill' => $t['requires_skill'] ?? null,
                    'requires_skill_level' => $t['requires_skill_level'] ?? null,
                    'yield_qty' => $yieldQty,
                    'xp' => $t['xp'],
                    'energy_cost' => $t['energy_cost'],
                    'action_seconds' => $this->tradeSkills->actionSeconds($key, $targetKey, $level, $speedPoints, $speedPct),
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

    /** Lightweight per-skill cooldown snapshot for the topbar pill (see stores/gatherCooldowns.js) — skips
     * the target/inventory lookups index() does since only each skill's own remaining cooldown is needed,
     * so it stays cheap to poll from any page, not just Gathering itself. */
    public function cooldowns(Request $request)
    {
        abort_unless(FeatureFlag::gate('trade_skills', $request->user()), 403, 'Gathering is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $rows = $character->tradeSkills()->get()->keyBy('skill_key');
        $petGatherSpeedPct = $character->effectiveStats()['pet_gather_speed_pct'] ?? 0;

        $cooldowns = collect($this->tradeSkills->all())
            ->map(function (array $meta, string $key) use ($character, $rows, $petGatherSpeedPct) {
                $row = $rows->get($key);
                if (! $row?->last_action_target || ! $row?->last_action_at) {
                    return null;
                }

                $level = $row->level ?? 1;
                $tool = $this->equippedToolBonus($character, $key);
                $speedPoints = $character->attributes_?->{self::SPEED_ATTR_BY_SKILL[$key] ?? ''} ?? 0;
                $speedPct = $tool['speed_pct'] + $petGatherSpeedPct;
                $actionSeconds = $this->tradeSkills->actionSeconds($key, $row->last_action_target, $level, $speedPoints, $speedPct);
                $remaining = max(0, $actionSeconds - (now()->getTimestamp() - $row->last_action_at->getTimestamp()));

                if ($remaining <= 0) {
                    return null;
                }

                return [
                    'skill' => $key,
                    'label' => $meta['label'],
                    'glyph' => $meta['glyph'],
                    'target_label' => $meta['targets'][$row->last_action_target]['label'] ?? $row->last_action_target,
                    'seconds_remaining' => $remaining,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['cooldowns' => $cooldowns]);
    }

    public function gather(Request $request, string $skillKey)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        abort_unless(in_array($skillKey, ['mining', 'woodchopping', 'smelting', 'foraging'], true), 404);

        $character->applyPassiveRegen();

        $meta = $this->tradeSkills->meta($skillKey);
        $data = $request->validate(['target' => ['required', Rule::in(array_keys($meta['targets']))]]);

        $outcome = $this->performGather($character, $skillKey, $data['target']);
        if (isset($outcome['error'])) {
            return response()->json(['message' => $outcome['error']], 422);
        }

        return response()->json($outcome['payload']);
    }

    /** Runs several gather actions in one request — the client queues clicks locally (see the shared
     * game-tick composable) and flushes them here every few ticks instead of one HTTP round-trip per
     * click, cutting request volume. Each action is validated and applied in order exactly as it would
     * be one at a time, so the same cooldown/energy/material rules still apply between queued actions. */
    public function gatherBatch(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'actions' => ['required', 'array', 'min:1', 'max:5'],
            'actions.*.skill' => ['required', Rule::in(['mining', 'woodchopping', 'smelting', 'foraging'])],
            'actions.*.target' => ['required', 'string'],
        ]);

        $character->applyPassiveRegen();

        $results = [];
        foreach ($data['actions'] as $action) {
            $skillKey = $action['skill'];
            $targetKey = $action['target'];
            $meta = $this->tradeSkills->meta($skillKey);

            if (! $meta || ! isset($meta['targets'][$targetKey])) {
                $results[] = ['skill' => $skillKey, 'target' => $targetKey, 'error' => 'Unknown skill or target.'];

                continue;
            }

            $outcome = $this->performGather($character, $skillKey, $targetKey);
            $results[] = ['skill' => $skillKey, 'target' => $targetKey, ...(
                isset($outcome['error'])
                    ? ['error' => $outcome['error']]
                    : ['gained' => $outcome['payload']['gained'], 'leveled_up' => $outcome['payload']['leveled_up']]
            )];

            $character->refresh();
        }

        return response()->json([
            'results' => $results,
            'energy' => $character->energy,
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }

    /** One gather action against an already-loaded, already-regen'd character. Returns
     * `['error' => string]` or `['payload' => [...]]` — never throws/aborts, so callers (single-action
     * `gather()` and the batch loop above) can turn a failure into a per-action result instead of a
     * hard HTTP error that would abort the rest of a batch. */
    private function performGather(Character $character, string $skillKey, string $targetKey): array
    {
        $meta = $this->tradeSkills->meta($skillKey);
        $target = $meta['targets'][$targetKey];

        $row = CharacterTradeSkill::firstOrCreate(
            ['character_id' => $character->id, 'skill_key' => $skillKey],
            ['level' => 1, 'xp' => 0]
        );

        if ($row->level < $target['unlock_level']) {
            return ['error' => "Requires {$meta['label']} level {$target['unlock_level']}."];
        }

        if (isset($target['requires_skill'])) {
            $otherLevel = $character->tradeSkills()->where('skill_key', $target['requires_skill'])->value('level') ?? 1;
            if ($otherLevel < $target['requires_skill_level']) {
                $otherLabel = $this->tradeSkills->meta($target['requires_skill'])['label'] ?? $target['requires_skill'];

                return ['error' => "Requires {$otherLabel} level {$target['requires_skill_level']}."];
            }
        }

        $tool = $this->equippedToolBonus($character, $skillKey);
        $speedPoints = $character->attributes_?->{self::SPEED_ATTR_BY_SKILL[$skillKey] ?? ''} ?? 0;
        $petGatherSpeedPct = $character->effectiveStats()['pet_gather_speed_pct'] ?? 0;
        $actionSeconds = $this->tradeSkills->actionSeconds($skillKey, $targetKey, $row->level, $speedPoints, $tool['speed_pct'] + $petGatherSpeedPct);

        if ($row->last_action_at && $actionSeconds > 0) {
            $remaining = $actionSeconds - (now()->getTimestamp() - $row->last_action_at->getTimestamp());
            if ($remaining > 0) {
                return ['error' => "Not ready yet — {$remaining}s remaining."];
            }
        }

        $energyCost = $target['energy_cost'];
        if ($character->energy < $energyCost) {
            return ['error' => "Not enough energy — need {$energyCost}."];
        }

        $luckBonusPct = $this->luckBonusPct($character);
        $qty = $this->tradeSkills->yieldQty($skillKey, $targetKey, $row->level, $tool['yield_bonus'], $luckBonusPct);
        $character->update(['last_action' => "{$meta['label']}: {$target['label']}"]);

        if (isset($target['input_key'])) {
            $inputItem = Item::where('key', $target['input_key'])->firstOrFail();
            $owned = Inventory::where('character_id', $character->id)->where('item_id', $inputItem->id)->first();
            $requiredQty = $target['input_qty'] * $qty;
            if (! $owned || $owned->qty < $requiredQty) {
                return ['error' => "Not enough {$inputItem->name} — need {$requiredQty}."];
            }
            $owned->decrement('qty', $requiredQty);
            if ($owned->fresh()->qty <= 0) {
                $owned->delete();
            }
        }

        $affected = Character::where('id', $character->id)
            ->where('energy', '>=', $energyCost)
            ->decrement('energy', $energyCost);

        if ($affected === 0) {
            // Race condition: energy was depleted by another request
            return ['error' => "Not enough energy — need {$energyCost}."];
        }

        $outputItem = Item::where('key', $targetKey)->firstOrFail();
        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $outputItem->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + $qty;
        $inventory->save();
        $character->increment(self::STAT_COLUMN_BY_SKILL[$skillKey]);
        $this->decayEquippedTool($character, $skillKey);
        $this->quests->progress($character, 'materials_gathered');

        $row->last_action_at = now();
        $row->last_action_target = $targetKey;
        $row->save();
        $leveledUp = $this->tradeSkills->grantXp($row, $target['xp']);

        $this->logAction($character, $skillKey, $targetKey, $qty, $target['xp']);

        return [
            'payload' => [
                'trade_skill' => $row->fresh(),
                'gained' => ['item' => $outputItem->only(['key', 'name', 'glyph']), 'qty' => $qty, 'xp' => $target['xp']],
                'leveled_up' => $leveledUp,
                'energy' => $character->fresh()->energy,
                'inventory' => $character->inventory()->with('item')->get(),
            ],
        ];
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
