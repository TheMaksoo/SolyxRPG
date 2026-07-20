<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterTradeSkill;
use App\Models\CraftingJob;
use App\Models\FeatureFlag;
use App\Models\GameConfig;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Recipe;
use App\Services\CraftingService;
use App\Services\DurabilityService;
use App\Services\QuestService;
use App\Services\TradeSkillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CraftingController extends Controller
{
    /** Flat Crafting trade-skill xp granted per successful craft, regardless of the rolled rarity. */
    private const CRAFTING_XP_PER_CRAFT = 25;

    /** Crafting Speed attribute points shave off craft time the same way the other trade skills' Speed attrs do. */
    private const ATTR_SPEED_PCT_PER_POINT = 2;
    private const ATTR_SPEED_CAP_PCT = 40;

    private const RARITY_ROLL_DEFAULTS = [
        'common' => ['min' => -5, 'max' => 10],
        'rare' => ['min' => 0, 'max' => 20],
        'epic' => ['min' => 10, 'max' => 35],
        'legendary' => ['min' => 20, 'max' => 60],
        'mythic' => ['min' => 25, 'max' => 100],
    ];

    /** Gear is never qty-stacked — each copy needs its own durability, so it always gets its own inventory row.
     * 'quiver' is the ranger's second, simultaneously-equippable slot alongside their bow (weapon) — see
     * ItemSeeder for the quiver line and InventoryController::equip() for how the generic per-type equip
     * logic makes that "just work" without any bow/quiver conflict handling needed. */
    private const GEAR_TYPES = ['weapon', 'armor', 'pickaxe', 'axe', 'sickle', 'hammer', 'quiver'];

    public function __construct(
        private CraftingService $crafting,
        private TradeSkillService $tradeSkills,
        private DurabilityService $durability,
        private QuestService $quests = new QuestService(),
    ) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('crafting', $request->user()), 403, 'Crafting is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        // Class-locked recipes are shown (greyed/locked in CraftingPage.vue) rather than hidden, so every
        // class can see what the other three craft — only the ones matching your class (or unrestricted,
        // class_key === null) are actually craftable.
        $recipes = Recipe::where('enabled', true)->with('resultItem')->get()->values();

        $inventoryByItem = Inventory::query()
            ->where('character_id', $character->id)
            ->selectRaw('item_id, SUM(qty) as total_qty')
            ->groupBy('item_id')
            ->pluck('total_qty', 'item_id');

        $materialIds = collect($recipes)
            ->flatMap(fn (Recipe $recipe) => collect($recipe->materials_json)->pluck('item_id'))
            ->unique()
            ->values();

        $materialsById = Item::query()
            ->whereIn('id', $materialIds)
            ->get(['id', 'name', 'glyph'])
            ->keyBy('id');

        $craftingLevel = $this->craftingLevel($character);
        $craftSpeedPct = $request->user()->vipCraftingSpeedBonus();
        $craftSpeedAttr = $character->attributes_?->crafting_speed ?? 0;
        $hammerSpeedPct = $this->equippedHammerSpeedPct($character) + ($character->effectiveStats()['pet_craft_speed_pct'] ?? 0);

        $recipes = $recipes->map(function (Recipe $recipe) use ($inventoryByItem, $materialsById, $craftSpeedPct, $craftSpeedAttr, $hammerSpeedPct, $character) {
            $materialsDetailed = collect($recipe->materials_json)->map(function (array $material) use ($inventoryByItem, $materialsById) {
                $owned = (int) ($inventoryByItem[$material['item_id']] ?? 0);
                $item = $materialsById->get($material['item_id']);

                return [
                    'item_id' => $material['item_id'],
                    'required_qty' => (int) $material['qty'],
                    'owned_qty' => $owned,
                    'has_enough' => $owned >= (int) $material['qty'],
                    'name' => $item?->name ?? 'Unknown Material',
                    'glyph' => $item?->glyph ?? '',
                ];
            })->values();

            $isGear = in_array($recipe->resultItem->type, self::GEAR_TYPES, true);
            $levelUnlocked = $character->level >= $recipe->min_level;
            $canAffordGold = $recipe->gold_cost <= 0 || $character->gold >= $recipe->gold_cost;
            $classLocked = $recipe->resultItem->class_key !== null && $recipe->resultItem->class_key !== $character->base_class;

            return [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'result_item_id' => $recipe->result_item_id,
                'result_item' => $recipe->resultItem,
                'materials_json' => $recipe->materials_json,
                'materials_detailed' => $materialsDetailed,
                'result_qty' => $recipe->result_qty,
                'min_level' => $recipe->min_level,
                'gold_cost' => $recipe->gold_cost,
                'can_afford_gold' => $canAffordGold,
                'level_unlocked' => $levelUnlocked,
                'class_locked' => $classLocked,
                'can_craft' => ! $classLocked && $levelUnlocked && $canAffordGold && ! $materialsDetailed->contains(fn (array $m) => ! $m['has_enough']),
                'is_gear' => $isGear,
                'craft_seconds' => $this->craftSeconds($recipe->craft_seconds, $craftSpeedPct, $craftSpeedAttr, $hammerSpeedPct),
            ];
        })->values();

        return response()->json([
            'recipes' => $recipes,
            'crafting_level' => $craftingLevel,
            'rarity_odds' => $this->crafting->odds($craftingLevel),
            'craft_speed_bonus_pct' => $craftSpeedPct,
        ]);
    }

    public function craft(Request $request, Recipe $recipe)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->level < $recipe->min_level) {
            return response()->json(['message' => "Requires character level {$recipe->min_level}."], 422);
        }

        $itemClass = $recipe->resultItem->class_key;
        if ($itemClass !== null && $itemClass !== $character->base_class) {
            return response()->json(['message' => "That recipe is locked to the {$itemClass} class."], 403);
        }

        $maxSlots = $this->maxQueueSlots($character, $request->user());
        $activeJobs = CraftingJob::where('character_id', $character->id)->whereNull('collected_at')->count();
        if ($activeJobs >= $maxSlots) {
            return response()->json(['message' => "Crafting queue full ({$activeJobs}/{$maxSlots}) — collect a finished craft first."], 422);
        }

        foreach ($recipe->materials_json as $material) {
            $owned = Inventory::where('character_id', $character->id)->where('item_id', $material['item_id'])->first();
            if (! $owned || $owned->qty < $material['qty']) {
                return response()->json(['message' => 'Missing required materials.'], 422);
            }
        }

        if ($recipe->gold_cost > 0 && $character->gold < $recipe->gold_cost) {
            return response()->json(['message' => 'Not enough gold to craft this.'], 422);
        }

        $stats = $character->effectiveStats();
        $luck = (int) ($stats['luck'] ?? 0);
        $craftingLevel = $this->craftingLevel($character);
        $craftSpeedPct = $request->user()->vipCraftingSpeedBonus();
        $craftSpeedAttr = $character->attributes_?->crafting_speed ?? 0;
        $hammerSpeedPct = $this->equippedHammerSpeedPct($character) + ($stats['pet_craft_speed_pct'] ?? 0);
        $job = null;

        DB::transaction(function () use ($recipe, $character, $luck, $craftingLevel, $craftSpeedPct, $craftSpeedAttr, $hammerSpeedPct, &$job) {
            foreach ($recipe->materials_json as $material) {
                $owned = Inventory::where('character_id', $character->id)->where('item_id', $material['item_id'])->first();
                $owned->decrement('qty', $material['qty']);
                if ($owned->fresh()->qty <= 0) {
                    $owned->delete();
                }
            }

            if ($recipe->gold_cost > 0) {
                $character->decrement('gold', $recipe->gold_cost);
            }

            $resultItem = $recipe->resultItem;
            $rarity = $resultItem->rarity;
            $rollPct = null;

            if (in_array($resultItem->type, self::GEAR_TYPES, true)) {
                $rarity = $this->crafting->roll($craftingLevel);
                $range = $this->applyLuckToRollRange($this->rarityRollRange($rarity), $luck);
                $rollPct = random_int($range['min'], $range['max']);
                $resultItem = $this->createCraftedVariant($resultItem, 1 + ($rollPct / 100), $luck, $rarity);
            }

            $seconds = $this->craftSeconds($recipe->craft_seconds, $craftSpeedPct, $craftSpeedAttr, $hammerSpeedPct);

            // Only one craft actually cooks at a time — queuing more just lines them up. Each new job starts
            // when the last-queued one finishes, rather than every queued job counting down in parallel.
            $queueFreeAt = CraftingJob::where('character_id', $character->id)
                ->whereNull('collected_at')
                ->max('completes_at');
            $startsAt = $queueFreeAt ? now()->max($queueFreeAt) : now();

            $job = CraftingJob::create([
                'character_id' => $character->id,
                'recipe_id' => $recipe->id,
                'result_item_id' => $resultItem->id,
                'result_qty' => $recipe->result_qty,
                'rarity' => $rarity,
                'roll_pct' => $rollPct,
                'started_at' => $startsAt,
                'completes_at' => $startsAt->copy()->addSeconds($seconds),
            ]);

            $craftingSkill = CharacterTradeSkill::firstOrCreate(
                ['character_id' => $character->id, 'skill_key' => 'crafting'],
                ['level' => 1, 'xp' => 0]
            );
            $this->tradeSkills->grantXp($craftingSkill, self::CRAFTING_XP_PER_CRAFT);
            $character->increment('times_crafted');
        });

        return response()->json([
            'job' => $job->load('resultItem', 'recipe'),
            'inventory' => $character->inventory()->with('item')->get(),
        ]);
    }

    public function queue(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $jobs = CraftingJob::where('character_id', $character->id)
            ->whereNull('collected_at')
            ->with(['resultItem', 'recipe'])
            ->orderBy('started_at')
            ->get()
            ->map(fn (CraftingJob $job) => [
                'id' => $job->id,
                'recipe' => $job->recipe,
                'result_item' => $job->resultItem,
                'rarity' => $job->rarity,
                'roll_pct' => $job->roll_pct,
                'completes_at' => $job->completes_at,
                'seconds_remaining' => max(0, $job->completes_at->getTimestamp() - now()->getTimestamp()),
                'is_ready' => $job->isReady(),
            ]);

        return response()->json([
            'jobs' => $jobs,
            'max_slots' => $this->maxQueueSlots($character, $request->user()),
        ]);
    }

    public function collect(Request $request, CraftingJob $job)
    {
        $character = $request->user()->character;
        abort_unless($character && $job->character_id === $character->id, 403, 'This crafting job belongs to a different character.');
        abort_if($job->collected_at !== null, 422, 'Already collected.');

        if (! $job->isReady()) {
            return response()->json(['message' => 'Still crafting — not ready yet.'], 422);
        }

        $resultItem = $job->resultItem;
        if (in_array($resultItem->type, self::GEAR_TYPES, true)) {
            $max = $this->durability->maxDurability($resultItem->rarity);
            $inventory = Inventory::create([
                'character_id' => $character->id, 'item_id' => $job->result_item_id, 'qty' => 1, 'equipped' => false,
                'durability' => $max, 'durability_max' => $max,
            ]);
        } else {
            $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $job->result_item_id, 'equipped' => false]);
            $inventory->qty = ($inventory->qty ?? 0) + max(1, $job->result_qty);
            $inventory->save();
        }

        $job->update(['collected_at' => now()]);
        $this->quests->progress($character, 'items_crafted');

        return response()->json([
            'inventory' => $character->inventory()->with('item')->get(),
            'crafted_item' => $inventory->load('item'),
        ]);
    }

    /** The character's Crafting trade-skill level (defaults to 1 if never crafted before). */
    private function craftingLevel($character): int
    {
        return $character->tradeSkills()->where('skill_key', 'crafting')->value('level') ?? 1;
    }

    /** Base 1 slot + VIP tier bonus + a bonus slot at character level 20/40/60 (matching the class-path tier caps). */
    private function maxQueueSlots($character, $user): int
    {
        return 1 + $user->vipCraftQueueBonus() + intdiv(min($character->level, 60), 20);
    }

    /** Applies the VIP crafting-speed %, the Crafting Speed attribute's % reduction, and an equipped Hammer's
     * % reduction to a recipe's base craft time, floored at 5s. */
    private function craftSeconds(int $baseSeconds, float $vipSpeedPct, int $attrPoints = 0, float $toolSpeedPct = 0): int
    {
        $attrPct = min(self::ATTR_SPEED_CAP_PCT, $attrPoints * self::ATTR_SPEED_PCT_PER_POINT);
        $seconds = $baseSeconds * (1 - $vipSpeedPct / 100) * (1 - $attrPct / 100) * (1 - $toolSpeedPct / 100);

        return max(5, (int) round($seconds));
    }

    /** Reads the equipped Hammer's craft_speed_pct stat, or zero if nothing's equipped/it's broken. */
    private function equippedHammerSpeedPct(Character $character): float
    {
        $tool = Inventory::where('character_id', $character->id)
            ->where('equipped', true)
            ->whereHas('item', fn ($q) => $q->where('type', 'hammer'))
            ->with('item')
            ->first();

        if ($tool && $tool->durability_max !== null && $tool->durability <= 0) {
            return 0; // broken tool contributes nothing until repaired
        }

        return $tool?->item->stat_json['craft_speed_pct'] ?? 0;
    }

    private function createCraftedVariant(Item $baseItem, float $multiplier, int $luck, string $rarity): Item
    {
        $stats = $baseItem->stat_json ?? [];
        $scaledStats = [];

        foreach ($stats as $key => $value) {
            if (is_numeric($value)) {
                $scaledStats[$key] = max(0, (int) round(((float) $value) * $multiplier));
                continue;
            }

            $scaledStats[$key] = $value;
        }

        $priceGold = $this->craftedValueFromStats($baseItem, $scaledStats, $multiplier, $luck);

        return Item::create([
            'key' => $baseItem->key.'_crafted_'.Str::lower(Str::random(10)),
            'name' => $baseItem->name,
            'type' => $baseItem->type,
            'weapon_category' => $baseItem->weapon_category,
            'rarity' => $rarity,
            'glyph' => $baseItem->glyph,
            'description' => $baseItem->description,
            'stat_json' => $scaledStats,
            'price_gold' => $priceGold,
            'price_gems' => null,
            'enabled' => false,
            'tester_only' => true,
        ]);
    }

    private function rarityRollRange(string $rarity): array
    {
        $fallback = self::RARITY_ROLL_DEFAULTS[$rarity] ?? self::RARITY_ROLL_DEFAULTS['common'];

        $min = (int) round(GameConfig::number("crafted_roll_{$rarity}_min_pct", $fallback['min']));
        $max = (int) round(GameConfig::number("crafted_roll_{$rarity}_max_pct", $fallback['max']));

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max];
    }

    private function applyLuckToRollRange(array $range, int $luck): array
    {
        if ($luck <= 0) {
            return $range;
        }

        $minDivisor = max(1, (int) round(GameConfig::number('luck_roll_min_shift_divisor', 10)));
        $maxDivisor = max(1, (int) round(GameConfig::number('luck_roll_max_shift_divisor', 4)));
        $minShift = intdiv($luck, $minDivisor);
        $maxShift = intdiv($luck, $maxDivisor);

        return [
            'min' => $range['min'] + $minShift,
            'max' => $range['max'] + $maxShift,
        ];
    }

    private function craftedValueFromStats(Item $baseItem, array $scaledStats, float $multiplier, int $luck): int
    {
        $baseValue = (int) ($baseItem->price_gold ?? (($baseItem->price_gems ?? 0) * 20));
        if ($baseValue <= 0) {
            $baseValue = max(1, (int) round(GameConfig::number('crafted_value_min_base', 100)));
        }

        $numericStatTotal = 0;
        foreach ($scaledStats as $value) {
            if (is_numeric($value)) {
                $numericStatTotal += max(0, (float) $value);
            }
        }

        $statsWeight = GameConfig::number('crafted_value_stat_weight', 6);
        $rollWeight = GameConfig::number('crafted_value_roll_weight', 1.5);
        $luckWeight = GameConfig::number('crafted_value_luck_weight', 4);

        $statsValue = (int) round($numericStatTotal * $statsWeight);
        $rollValue = (int) round(max(0, $multiplier - 1) * $baseValue * $rollWeight);
        $luckValue = (int) round(max(0, $luck) * $luckWeight);

        return max($baseValue, $baseValue + $statsValue + $rollValue + $luckValue);
    }
}
