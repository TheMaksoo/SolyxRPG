<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\MarketListing;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Player-to-player marketplace: list an item from your inventory for gold (or gems for
 * legendary/mythic), other players browse and buy it. Built alongside the crafting class-lock
 * removal (see CraftingController) so a dedicated crafter can supply gear for every class and sell
 * it here instead of only equipping their own class's kit.
 *
 * Listings escrow the item out of the seller's inventory the moment they're created (mirrors how
 * crafting consumes materials up front) — a listing is a real commitment, not a soft reservation,
 * so there's no way to double-list or double-spend the same stack. Unsold listings auto-expire (see
 * LISTING_DURATION_HOURS) and the escrowed item is returned via `market:expire-listings`
 * (routes/console.php).
 */
class MarketplaceController extends Controller
{
    private const LISTING_DURATION_HOURS = 72;

    /** Base concurrent active listing cap — VIP tiers add on top, see User::VIP_TIER_MARKET_LISTINGS. */
    private const BASE_LISTING_CAP = 10;

    /** Only the N most recent resolved (sold/cancelled/expired) listings per seller are kept as visible
     * history — older ones are pruned the moment a NEWER one resolves, so a character's history table
     * never grows past this regardless of how long the 30-day time-based cleanup (CleanupStaleData)
     * takes to catch up. */
    private const MAX_HISTORY_PER_CHARACTER = 30;

    /** Cut taken from the sale price on a successful sale — a gold sink, same role VIP/gem purchases
     * play elsewhere, so the marketplace doesn't just recirculate gold with zero drain on the economy.
     * Gem-priced listings also pay this fee (in gems). */
    private const MARKET_FEE_PCT = 10;

    /** Cancelling your own listing early costs a cut of the LISTED price (not charged to a buyer, since
     * there isn't one) — otherwise listing-then-cancelling is a free way to "reserve" a price check
     * with zero commitment. */
    private const CANCEL_FEE_PCT = 10;

    /** Gear is never qty-stacked (each copy has its own durability) — mirrors CraftingController::GEAR_TYPES. */
    private const GEAR_TYPES = ['weapon', 'armor', 'shield', 'pickaxe', 'axe', 'sickle', 'hammer', 'quiver', 'trinket'];

    /** Only legendary and mythic items may be listed for gems. */
    private const GEM_LISTABLE_RARITIES = ['legendary', 'mythic'];

    private const PAGE_SIZE = 20;

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('marketplace', $request->user()), 403, 'The Marketplace is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        // Expire overdue listings on the first page only — keeps browsing fast while still
        // cleaning up stale rows in a timely manner (the scheduled command handles the rest).
        if ((int) $request->query('page', 1) === 1) {
            $this->expireDueListings();
        }

        $query = MarketListing::where('status', 'active')
            ->where('seller_character_id', '!=', $character->id)
            ->with(['item', 'sellerCharacter']);

        // --- filters ---
        $search = $request->query('search');
        if ($search) {
            $query->whereHas('item', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $seller = $request->query('seller');
        if ($seller) {
            $query->whereHas('sellerCharacter', fn ($q) => $q->where('name', 'like', "%{$seller}%"));
        }

        $itemType = $request->query('item_type');
        if ($itemType && $itemType !== 'all') {
            $query->whereHas('item', fn ($q) => $q->where('type', $itemType));
        }

        $rarity = $request->query('rarity');
        if ($rarity && $rarity !== 'all') {
            $query->whereHas('item', fn ($q) => $q->where('rarity', $rarity));
        }

        $currency = $request->query('currency'); // 'gold'|'gems'|'all'
        if ($currency === 'gems') {
            $query->whereNotNull('price_gems');
        } elseif ($currency === 'gold') {
            $query->whereNull('price_gems');
        }

        // --- sort ---
        $sort = $request->query('sort', 'newest');
        match ($sort) {
            'oldest'     => $query->oldest(),
            'price_asc'  => $this->applyPriceSort($query, $currency, 'asc'),
            'price_desc' => $this->applyPriceSort($query, $currency, 'desc'),
            default      => $query->latest(),  // 'newest'
        };

        $paginated = $query->paginate(self::PAGE_SIZE, ['*'], 'page', $request->query('page', 1));

        return response()->json([
            'listings'  => $paginated->map(fn (MarketListing $l) => $this->present($l)),
            'total'     => $paginated->total(),
            'page'      => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page'  => self::PAGE_SIZE,
        ]);
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

        return response()->json([
            'listings'       => $listings,
            'listing_cap'    => $this->listingCap($request->user()),
            'cancel_fee_pct' => max(0, self::CANCEL_FEE_PCT - $request->user()->vipMarketFeeReductionPct()),
        ]);
    }

    /** Base cap plus this account's VIP bonus (see User::VIP_TIER_MARKET_LISTINGS) — shared by the cap
     * check on store() and the "X / cap" readout on the My Listings tab. */
    private function listingCap(User $user): int
    {
        return self::BASE_LISTING_CAP + $user->vipMarketListingBonus();
    }

    public function store(Request $request)
    {
        abort_unless(FeatureFlag::gate('marketplace', $request->user()), 403, 'The Marketplace is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'inventory_id' => ['required', 'exists:inventories,id'],
            'qty'          => ['required', 'integer', 'min:1'],
            'price_gold'   => ['required', 'integer', 'min:1'],
            'price_gems'   => ['nullable', 'integer', 'min:1'],
        ]);

        $activeCount = MarketListing::where('seller_character_id', $character->id)->where('status', 'active')->count();
        $listingCap  = $this->listingCap($request->user());
        if ($activeCount >= $listingCap) {
            return response()->json(['message' => "You've hit your active listing limit ({$listingCap}). Cancel or wait for one to sell/expire (VIP raises this cap)."], 422);
        }

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

        // Gem pricing is only allowed for legendary and mythic items.
        if (!empty($data['price_gems']) && !in_array($inventory->item->rarity, self::GEM_LISTABLE_RARITIES, true)) {
            return response()->json(['message' => 'Only legendary and mythic items can be listed for gems.'], 422);
        }

        $listing = DB::transaction(function () use ($inventory, $data, $character) {
            $inventory->decrement('qty', $data['qty']);
            if ($inventory->fresh()->qty <= 0) {
                $inventory->delete();
            }

            return MarketListing::create([
                'seller_character_id' => $character->id,
                'item_id'             => $inventory->item_id,
                'qty'                 => $data['qty'],
                'durability'          => $inventory->durability,
                'durability_max'      => $inventory->durability_max,
                'price_gold'          => $data['price_gold'],
                'price_gems'          => $data['price_gems'] ?? null,
                'status'              => 'active',
                'expires_at'          => now()->addHours(self::LISTING_DURATION_HOURS),
            ]);
        });

        return response()->json(['listing' => $this->present($listing->load('item')), 'inventory' => $character->inventory()->with('item')->get()]);
    }

    public function buy(Request $request, MarketListing $listing)
    {
        abort_unless(FeatureFlag::gate('marketplace', $request->user()), 403, 'The Marketplace is not currently available.');

        $character   = $request->user()->character;
        abort_unless($character, 404);
        abort_if($listing->seller_character_id === $character->id, 422, 'You can\'t buy your own listing.');

        if ($listing->isExpired()) {
            $this->expireDueListings();
        }

        if ($listing->fresh()->status !== 'active') {
            return response()->json(['message' => 'That listing is no longer available.'], 422);
        }

        $useGems    = $listing->price_gems !== null;
        $buyer      = $request->user();
        $sellerUser = $listing->sellerCharacter->user;

        if ($useGems) {
            if ($buyer->gems < $listing->price_gems) {
                return response()->json(['message' => 'Not enough gems.'], 422);
            }
        } else {
            if ($character->gold < $listing->price_gold) {
                return response()->json(['message' => 'Not enough gold.'], 422);
            }
        }

        DB::transaction(function () use ($listing, $character, $buyer, $sellerUser, $useGems) {
            if ($useGems) {
                $feePct = max(0, self::MARKET_FEE_PCT - $sellerUser->vipMarketFeeReductionPct());
                $fee    = (int) floor($listing->price_gems * $feePct / 100);
                $net    = $listing->price_gems - $fee;

                $buyer->decrement('gems', $listing->price_gems);
                GemLedger::log($buyer, -$listing->price_gems, "market_buy:{$listing->id}", $character);

                $sellerUser->increment('gems', $net);
                GemLedger::log($sellerUser, $net, "market_sell:{$listing->id}", $listing->sellerCharacter);
            } else {
                $character->decrement('gold', $listing->price_gold);

                $feePct = max(0, self::MARKET_FEE_PCT - $sellerUser->vipMarketFeeReductionPct());
                $fee    = (int) floor($listing->price_gold * $feePct / 100);
                $listing->sellerCharacter->increment('gold', $listing->price_gold - $fee);
            }

            $this->depositItem($character, $listing);

            $listing->update([
                'status'             => 'sold',
                'buyer_character_id' => $character->id,
                'sold_at'            => now(),
            ]);

            $this->trimHistory($listing->seller_character_id);
        });

        return response()->json(['inventory' => $character->inventory()->with('item')->get(), 'character' => $character->fresh()]);
    }

    public function cancel(Request $request, MarketListing $listing)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($listing->seller_character_id !== $character->id, 403, 'That\'s not your listing.');
        abort_if($listing->status !== 'active', 422, 'Only active listings can be cancelled.');

        $cancelFeePct = max(0, self::CANCEL_FEE_PCT - $request->user()->vipMarketFeeReductionPct());
        $useGems      = $listing->price_gems !== null;
        $fee          = $useGems
            ? (int) ceil($listing->price_gems * $cancelFeePct / 100)
            : (int) ceil($listing->price_gold * $cancelFeePct / 100);
        $sellerUser   = $request->user();

        DB::transaction(function () use ($listing, $character, $fee, $useGems, $sellerUser) {
            $this->depositItem($character, $listing);
            if ($useGems) {
                $sellerUser->decrement('gems', min($fee, $sellerUser->gems));
                GemLedger::log($sellerUser, -$fee, "market_cancel_fee:{$listing->id}", $character);
            } else {
                $character->update(['gold' => max(0, $character->gold - $fee)]);
            }
            $listing->update(['status' => 'cancelled']);
            $this->trimHistory($character->id);
        });

        return response()->json([
            'inventory'            => $character->inventory()->with('item')->get(),
            'character'            => $character->fresh(),
            'cancel_fee'           => $fee,
            'cancel_fee_currency'  => $useGems ? 'gems' : 'gold',
        ]);
    }

    private function applyPriceSort($query, ?string $currency, string $direction): void
    {
        if ($currency === 'gems') {
            $query->orderBy('price_gems', $direction);

            return;
        }

        if ($currency === 'gold') {
            $query->orderBy('price_gold', $direction);

            return;
        }

        $query->orderByRaw("CASE WHEN price_gems IS NULL THEN price_gold ELSE price_gems END {$direction}");
    }

    /** Returns/awards a listing's escrowed item+qty to a character's inventory — used on both a
     * successful buy (to the buyer) and a cancel/expiry (back to the seller). Gear keeps its
     * snapshotted durability rather than coming back at full/zero. */
    private function depositItem(Character $character, MarketListing $listing): void
    {
        // The catalog item this listing escrowed can end up deleted out from under it (a GM removing a
        // retired item, e.g.) — there's nothing to hand back in that case, so skip the deposit rather
        // than crash the whole marketplace page (expireDueListings runs on every browse) for every
        // player over one orphaned row. Still lets the caller mark the listing resolved.
        if (! $listing->item) {
            Log::warning('Marketplace listing references a deleted item — skipping deposit.', [
                'listing_id' => $listing->id,
                'item_id' => $listing->item_id,
            ]);

            return;
        }

        $isGear = in_array($listing->item->type, self::GEAR_TYPES, true);

        if ($isGear) {
            Inventory::create([
                'character_id'   => $character->id,
                'item_id'        => $listing->item_id,
                'qty'            => 1,
                'equipped'       => false,
                'durability'     => $listing->durability,
                'durability_max' => $listing->durability_max,
            ]);

            return;
        }

        $inventory      = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $listing->item_id, 'equipped' => false]);
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
                    $this->trimHistory($listing->seller_character_id);
                });
            });
    }

    /** Keeps only the MAX_HISTORY_PER_CHARACTER most recently resolved listings for a seller, deleting
     * the rest — called right after any listing resolves (sold/cancelled/expired) so history never
     * outgrows the cap between time-based cleanup runs (see CleanupStaleData). */
    private function trimHistory(int $characterId): void
    {
        $keepIds = MarketListing::where('seller_character_id', $characterId)
            ->whereIn('status', ['sold', 'cancelled', 'expired'])
            ->orderByDesc('updated_at')
            ->limit(self::MAX_HISTORY_PER_CHARACTER)
            ->pluck('id');

        MarketListing::where('seller_character_id', $characterId)
            ->whereIn('status', ['sold', 'cancelled', 'expired'])
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    private function present(MarketListing $listing): array
    {
        return [
            'id'             => $listing->id,
            'item'           => $listing->item,
            'qty'            => $listing->qty,
            'durability'     => $listing->durability,
            'durability_max' => $listing->durability_max,
            'price_gold'     => $listing->price_gold,
            'price_gems'     => $listing->price_gems,
            'status'         => $listing->status,
            'seller_name'    => $listing->sellerCharacter?->name,
            'buyer_name'     => $listing->buyerCharacter?->name,
            'expires_at'     => $listing->expires_at,
            'sold_at'        => $listing->sold_at,
            'created_at'     => $listing->created_at,
        ];
    }
}
