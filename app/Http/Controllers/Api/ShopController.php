<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;
use App\Services\DurabilityService;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /** Gear is never qty-stacked — each copy needs its own durability, so it always gets its own row. */
    private const GEAR_TYPES = ['weapon', 'armor', 'shield', 'quiver', 'pickaxe', 'axe', 'sickle', 'hammer'];

    public function __construct(private DurabilityService $durability) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('shop', $request->user()), 403, 'The Shop is not currently available.');

        $tab = $request->query('tab');
        $character = $request->user()->character;

        // Rarity tier unlock levels
        $tierLevels = ['common' => 1, 'rare' => 15, 'epic' => 20, 'legendary' => 35, 'mythic' => 50];
        $currentTier = null;
        $nextTier = null;

        if ($character) {
            // Find current craftable tier and next tier
            foreach ($tierLevels as $tier => $level) {
                if ($character->calculated_level >= $level) {
                    $currentTier = $tier;
                } elseif ($nextTier === null) {
                    $nextTier = $tier;
                    break;
                }
            }
        }

        $items = Item::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNotNull('price_gold')->orWhereNotNull('price_gems'))
            ->when($tab, fn ($q) => $q->where('type', $tab))
            ->when(
                $character,
                fn ($q) => $q->where(fn ($q2) => $q2->whereNull('class_key')->orWhere('class_key', $character->base_class))
            )
            ->orderBy('rarity')
            ->orderBy('name')
            ->get()
            ->map(function ($item) use ($character, $currentTier, $nextTier, $tierLevels) {
                // Determine if this item's tier is unlocked for crafting/gold purchase
                $itemTierLevel = $tierLevels[$item->rarity] ?? 999;
                $currentTierLevel = $currentTier ? ($tierLevels[$currentTier] ?? 0) : 0;
                $isTierUnlocked = $itemTierLevel <= $currentTierLevel;

                // Level unlock (min_level): allows buying with gems
                // Crafting unlock (tier): allows buying with gold
                $item->is_unlocked = $character && $character->calculated_level >= $item->min_level;
                $item->can_buy_with_gold = $isTierUnlocked && $item->price_gold !== null;
                $item->can_buy_with_gems = $item->is_unlocked && $item->price_gems !== null;

                // Display prices based on unlock status
                if ($isTierUnlocked && $item->price_gold) {
                    $item->display_price_gold = $item->price_gold;
                    $item->display_price_gems = $item->price_gems;
                } elseif ($item->is_unlocked && $item->price_gems) {
                    $item->display_price_gold = null;
                    $item->display_price_gems = $item->price_gems;
                } else {
                    $item->display_price_gold = null;
                    $item->display_price_gems = null;
                }

                return $item;
            });

        return response()->json(['items' => $items]);
    }

    public function buy(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['item_id' => ['required', 'exists:items,id']]);
        $item = Item::findOrFail($data['item_id']);

        if ($item->class_key !== null && $item->class_key !== $character->base_class) {
            return response()->json(['message' => 'That item is not usable by your class.'], 422);
        }

        if ($item->price_gold === null && $item->price_gems === null) {
            return response()->json(['message' => 'This item is not purchasable.'], 422);
        }

        // Determine current tier
        $tierLevels = ['common' => 1, 'rare' => 15, 'epic' => 20, 'legendary' => 35, 'mythic' => 50];
        $currentTier = null;

        foreach ($tierLevels as $tier => $level) {
            if ($character->calculated_level >= $level) {
                $currentTier = $tier;
            }
        }

        // Determine if this item's tier is unlocked for crafting/gold purchase
        $itemTierLevel = $tierLevels[$item->rarity] ?? 999;
        $currentTierLevel = $currentTier ? ($tierLevels[$currentTier] ?? 0) : 0;
        $isTierUnlocked = $itemTierLevel <= $currentTierLevel;

        // Level unlock (min_level): allows buying with gems
        // Crafting unlock (tier): allows buying with gold
        $canBuyWithGold = $isTierUnlocked && $item->price_gold !== null;
        $canBuyWithGems = $character->calculated_level >= $item->min_level && $item->price_gems !== null;

        if (!$canBuyWithGold && !$canBuyWithGems) {
            if ($character->calculated_level < $item->min_level) {
                return response()->json(['message' => "Requires level {$item->min_level}."], 422);
            }
            return response()->json(['message' => 'This item is not purchasable.'], 422);
        }

        // Prefer gold if available, otherwise use gems
        if ($canBuyWithGold) {
            if ($character->gold < $item->price_gold) {
                return response()->json(['message' => 'Not enough gold.'], 422);
            }
            $character->decrement('gold', $item->price_gold);
        } else {
            if ($character->gems < $item->price_gems) {
                return response()->json(['message' => 'Not enough gems.'], 422);
            }
            $character->decrement('gems', $item->price_gems);
            GemLedger::log($character, -$item->price_gems, "shop_buy:{$item->key}");
        }

        if (in_array($item->type, self::GEAR_TYPES, true)) {
            $max = $this->durability->maxDurability($item->rarity);
            $inventory = Inventory::create([
                'character_id' => $character->id, 'item_id' => $item->id, 'qty' => 1, 'equipped' => false,
                'durability' => $max, 'durability_max' => $max,
            ]);
        } else {
            $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
            $inventory->qty = ($inventory->qty ?? 0) + 1;
            $inventory->save();
        }

        return response()->json(['character' => $character->fresh(), 'inventory' => $inventory]);
    }
}
