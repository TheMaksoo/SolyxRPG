<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\Inventory;
use App\Models\MarketListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Player-to-player marketplace: list an item from your inventory for gold, other players browse and buy
 * it. Built alongside the crafting class-lock removal (see CraftingController) so a dedicated crafter can
 * supply gear for every class and sell it here instead of only equipping their own class's kit.
 *
 * Listings escrow the item out of the seller's inventory the moment they're created (mirrors how crafting
 * consumes materials up front) — a listing is a real commitment, not a soft reservation, so there's no way
 * to double-list or double-spend the same stack. Unsold listings auto-expire (see LISTING_DURATION_HOURS)
 * and the escrowed item is returned via `market:expire-listings` (routes/console.php).
 */
class MarketplaceController extends Controller
{
    private const LISTING_DURATION_HOURS = 72;

    /** Cut taken from the sale price on a successful sale — a gold sink, same role VIP/gem purchases play
     * elsewhere, so the marketplace doesn't just recirculate gold with zero drain on the economy. */
    private const MARKET_FEE_PCT = 5;

    /** Cancelling your own listing early costs a cut of the LISTED price (not charged to a buyer, since
     * there isn't one) — otherwise listing-then-cancelling is a free way to "reserve" a price check with
     * zero commitment. */
    private const CANCEL_FEE_PCT = 10;

    /** Gear is never qty-stacked (each copy has its own durability) — mirrors CraftingController::GEAR_TYPES. */
    private const GEAR_TYPES = ['weapon', 'armor', 'shield', 'pickaxe', 'axe', 'sickle', 'hammer', 'quiver'];

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('marketplace', $request->user()), 403, 'The Marketplace is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $this->expireDueListings();

        $listings = MarketListing::where('status', 'active')
            ->where('seller_character_id', '!=', $character->id)
            ->with(['item', 'sellerCharacter'])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (MarketListing $listing) => $this->present($listing));

        return response()->json(['listings' => $listings]);
    }

    public function mine(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $this->expireDueListings();

        $listings = MarketListing::where('seller_character_id', $character->id)
            ->with(['item', 'buyerCharacter'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (MarketListing $listing) => $this->present($listing));

        return response()->json(['listings' => $listings]);
    }

    public function store(Request $request)
    {
        abort_unless(FeatureFlag::gate('marketplace', $request->user()), 403, 'The Marketplace is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'inventory_id' => ['required', 'exists:inventories,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'price_gold' => ['required', 'integer', 'min:1'],
        ]);

        $inventory = Inventory::where('id', $data['inventory_id'])
            ->where('character_id', $character->id)
            ->with('item')
            ->firstOrFail();

        if ($inventory->equipped) {
            return response()->json(['message' => 'Unequip it before listing it.'], 422);
        }

        $isGear = in_array($inventory->item->type, self::GEAR_TYPES, true);
        if ($isGear && $data['qty'] != 1) {
            return response()->json(['message' => 'Gear is listed one piece at a time.'], 422);
        }

        if ($inventory->qty < $data['qty']) {
            return response()->json(['message' => 'You don\'t have that many to list.'], 422);
        }

        $listing = DB::transaction(function () use ($inventory, $data, $character) {
            $inventory->decrement('qty', $data['qty']);
            if ($inventory->fresh()->qty <= 0) {
                $inventory->delete();
            }

            return MarketListing::create([
                'seller_character_id' => $character->id,
                'item_id' => $inventory->item_id,
                'qty' => $data['qty'],
                'durability' => $inventory->durability,
                'durability_max' => $inventory->durability_max,
                'price_gold' => $data['price_gold'],
                'status' => 'active',
                'expires_at' => now()->addHours(self::LISTING_DURATION_HOURS),
            ]);
        });

        return response()->json(['listing' => $this->present($listing->load('item')), 'inventory' => $character->inventory()->with('item')->get()]);
    }

    public function buy(Request $request, MarketListing $listing)
    {
        abort_unless(FeatureFlag::gate('marketplace', $request->user()), 403, 'The Marketplace is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($listing->seller_character_id === $character->id, 422, 'You can\'t buy your own listing.');

        if ($listing->isExpired()) {
            $this->expireDueListings();
        }

        if ($listing->fresh()->status !== 'active') {
            return response()->json(['message' => 'That listing is no longer available.'], 422);
        }

        if ($character->gold < $listing->price_gold) {
            return response()->json(['message' => 'Not enough gold.'], 422);
        }

        DB::transaction(function () use ($listing, $character) {
            $character->decrement('gold', $listing->price_gold);

            $fee = (int) floor($listing->price_gold * self::MARKET_FEE_PCT / 100);
            $listing->sellerCharacter->increment('gold', $listing->price_gold - $fee);

            $this->depositItem($character, $listing);

            $listing->update([
                'status' => 'sold',
                'buyer_character_id' => $character->id,
                'sold_at' => now(),
            ]);
        });

        return response()->json(['inventory' => $character->inventory()->with('item')->get(), 'character' => $character->fresh()]);
    }

    public function cancel(Request $request, MarketListing $listing)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($listing->seller_character_id !== $character->id, 403, 'That\'s not your listing.');
        abort_if($listing->status !== 'active', 422, 'Only active listings can be cancelled.');

        $fee = (int) ceil($listing->price_gold * self::CANCEL_FEE_PCT / 100);

        DB::transaction(function () use ($listing, $character, $fee) {
            $this->depositItem($character, $listing);
            $character->update(['gold' => max(0, $character->gold - $fee)]);
            $listing->update(['status' => 'cancelled']);
        });

        return response()->json([
            'inventory' => $character->inventory()->with('item')->get(),
            'character' => $character->fresh(),
            'cancel_fee' => $fee,
        ]);
    }

    /** Returns/awards a listing's escrowed item+qty to a character's inventory — used on both a
     * successful buy (to the buyer) and a cancel/expiry (back to the seller). Gear keeps its
     * snapshotted durability rather than coming back at full/zero. */
    private function depositItem(Character $character, MarketListing $listing): void
    {
        $isGear = in_array($listing->item->type, self::GEAR_TYPES, true);

        if ($isGear) {
            Inventory::create([
                'character_id' => $character->id,
                'item_id' => $listing->item_id,
                'qty' => 1,
                'equipped' => false,
                'durability' => $listing->durability,
                'durability_max' => $listing->durability_max,
            ]);

            return;
        }

        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $listing->item_id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + $listing->qty;
        $inventory->save();
    }

    /** Sweeps past-due active listings inline on every browse/mine call, in addition to the scheduled
     * `market:expire-listings` command (routes/console.php) — keeps the list honest even if the
     * scheduler run hasn't hit yet. Public so the command can reuse the exact same logic. */
    public function expireDueListings(): void
    {
        MarketListing::where('status', 'active')
            ->where('expires_at', '<', now())
            ->with(['item', 'sellerCharacter'])
            ->get()
            ->each(function (MarketListing $listing) {
                DB::transaction(function () use ($listing) {
                    $this->depositItem($listing->sellerCharacter, $listing);
                    $listing->update(['status' => 'expired']);
                });
            });
    }

    private function present(MarketListing $listing): array
    {
        return [
            'id' => $listing->id,
            'item' => $listing->item,
            'qty' => $listing->qty,
            'durability' => $listing->durability,
            'durability_max' => $listing->durability_max,
            'price_gold' => $listing->price_gold,
            'status' => $listing->status,
            'seller_name' => $listing->sellerCharacter?->name,
            'buyer_name' => $listing->buyerCharacter?->name,
            'expires_at' => $listing->expires_at,
            'sold_at' => $listing->sold_at,
            'created_at' => $listing->created_at,
        ];
    }
}
