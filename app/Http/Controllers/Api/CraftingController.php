<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterTradeSkill;
use App\Models\CraftingJob;
use App\Models\GameConfig;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Recipe;
use App\Services\CraftingService;
use App\Services\TradeSkillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CraftingController extends Controller
{
    /** Flat Crafting trade-skill xp granted per successful craft, regardless of the rolled rarity. */
    private const CRAFTING_XP_PER_CRAFT = 25;

    private const RARITY_ROLL_DEFAULTS = [
        'common' => ['min' => -5, 'max' => 10],
        'rare' => ['min' => 0, 'max' => 20],
        'epic' => ['min' => 10, 'max' => 35],
        'legendary' => ['min' => 20, 'max' => 60],
        'mythic' => ['min' => 25, 'max' => 100],
    ];

    public function __construct(
        private CraftingService $crafting,
        private TradeSkillService $tradeSkills,
    ) {}

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $recipes = Recipe::where('enabled', true)->with('resultItem')->get();

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

        $recipes = $recipes->map(function (Recipe $recipe) use ($inventoryByItem, $materialsById, $craftSpeedPct) {
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

            $isGear = in_array($recipe->resultItem->type, ['weapon', 'armor'], true);

            return [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'result_item_id' => $recipe->result_item_id,
                'result_item' => $recipe->resultItem,
                'materials_json' => $recipe->materials_json,
                'materials_detailed' => $materialsDetailed,
                'can_craft' => ! $materialsDetailed->contains(fn (array $m) => ! $m['has_enough']),
                'is_gear' => $isGear,
                'craft_seconds' => $this->craftSeconds($recipe->craft_seconds, $craftSpeedPct),
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

        if (CraftingJob::where('character_id', $character->id)->whereNull('collected_at')->exists()) {
            return response()->json(['message' => 'Finish and collect your current craft first.'], 422);
        }

        foreach ($recipe->materials_json as $material) {
            $owned = Inventory::where('character_id', $character->id)->where('item_id', $material['item_id'])->first();
            if (! $owned || $owned->qty < $material['qty']) {
                return response()->json(['message' => 'Missing required materials.'], 422);
            }
        }

        $luck = (int) (($character->effectiveStats()['luck'] ?? 0));
        $craftingLevel = $this->craftingLevel($character);
        $craftSpeedPct = $request->user()->vipCraftingSpeedBonus();
        $job = null;

        DB::transaction(function () use ($recipe, $character, $luck, $craftingLevel, $craftSpeedPct, &$job) {
            foreach ($recipe->materials_json as $material) {
                $owned = Inventory::where('character_id', $character->id)->where('item_id', $material['item_id'])->first();
                $owned->decrement('qty', $material['qty']);
                if ($owned->fresh()->qty <= 0) {
                    $owned->delete();
                }
            }

            $resultItem = $recipe->resultItem;
            $rarity = $resultItem->rarity;
            $rollPct = null;

            if (in_array($resultItem->type, ['weapon', 'armor'], true)) {
                $rarity = $this->crafting->roll($craftingLevel);
                $range = $this->applyLuckToRollRange($this->rarityRollRange($rarity), $luck);
                $rollPct = random_int($range['min'], $range['max']);
                $resultItem = $this->createCraftedVariant($resultItem, 1 + ($rollPct / 100), $luck, $rarity);
            }

            $seconds = $this->craftSeconds($recipe->craft_seconds, $craftSpeedPct);

            $job = CraftingJob::create([
                'character_id' => $character->id,
                'recipe_id' => $recipe->id,
                'result_item_id' => $resultItem->id,
                'rarity' => $rarity,
                'roll_pct' => $rollPct,
                'started_at' => now(),
                'completes_at' => now()->addSeconds($seconds),
            ]);

            $craftingSkill = CharacterTradeSkill::firstOrCreate(
                ['character_id' => $character->id, 'skill_key' => 'crafting'],
                ['level' => 1, 'xp' => 0]
            );
            $this->tradeSkills->grantXp($craftingSkill, self::CRAFTING_XP_PER_CRAFT);
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

        return response()->json(['jobs' => $jobs]);
    }

    public function collect(Request $request, CraftingJob $job)
    {
        $character = $request->user()->character;
        abort_unless($character && $job->character_id === $character->id, 403);
        abort_if($job->collected_at !== null, 422, 'Already collected.');

        if (! $job->isReady()) {
            return response()->json(['message' => 'Still crafting — not ready yet.'], 422);
        }

        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $job->result_item_id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + 1;
        $inventory->save();

        $job->update(['collected_at' => now()]);

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

    /** Applies the VIP crafting-speed % reduction to a recipe's base craft time, floored at 5s. */
    private function craftSeconds(int $baseSeconds, float $speedBonusPct): int
    {
        return max(5, (int) round($baseSeconds * (1 - $speedBonusPct / 100)));
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
