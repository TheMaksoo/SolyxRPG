<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Http\Request;
use Stripe\StripeClient;

/** One-time, real-money-only cosmetic bundle sold while Solyx is in beta — see
 * database/seeders/CosmeticSeeder.php's "Founder Beta Pack" block for the actual title/icon/frame/
 * banner it grants. Never purchasable with gems. There's no fixed end date (beta itself doesn't have
 * one yet) — availability is gated by the 'founder_pack' FeatureFlag instead, so it can be switched
 * off from the GM Console the moment beta actually ends, without a code deploy. */
class FounderPackController extends Controller
{
    private const PRICE_CENTS = 1499;
    /** A generous "thank you" on top of the cosmetics, but deliberately not so large it turns the pack
     * into a gems-arbitrage play — 10k would be worth more (at the Gem Store's own per-gem rate) than
     * the pack's entire cash price, which would undercut the "cosmetic prestige bundle" framing. */
    private const GEM_BONUS = 5000;
    /** What GEM_BONUS is worth at the Gem Store's own rate (StoreController::GEM_PACKS['gems_4000'] —
     * 1999 cents / 4000 gems ≈ 0.49975/gem), shown next to the bonus so it's clear the gems alone are
     * worth more than the whole pack's price, not a made-up "value" figure. */
    private const GEM_BONUS_VALUE_CENTS = 2499;

    private const COSMETIC_KEYS = ['title_founder', 'icon_founder', 'frame_founder', 'banner_founder', 'color_gold'];

    public static function available(User $user): bool
    {
        return FeatureFlag::gate('founder_pack', $user);
    }

    public function index(Request $request)
    {
        return response()->json([
            'available' => self::available($request->user()),
            'price_cents' => self::PRICE_CENTS,
            'gem_bonus' => self::GEM_BONUS,
            'gem_bonus_value_cents' => self::GEM_BONUS_VALUE_CENTS,
            'is_founder' => $request->user()->is_founder,
            // Ranked by lifetime_spend_cents (real money spent across the whole account — gem packs,
            // VIP subscriptions, Lifetime VIP, this pack) but the amount itself is never returned to
            // the client — this is a "who's a Founder, in what order" list, nothing more.
            'founders' => User::where('is_founder', true)
                ->orderByDesc('lifetime_spend_cents')
                ->orderBy('founder_purchased_at')
                ->with('character:id,user_id,name')
                ->get()
                ->map(fn (User $u) => [
                    'name' => $u->character?->name ?? $u->name,
                    'founded_at' => $u->founder_purchased_at,
                ]),
        ]);
    }

    public function purchase(Request $request)
    {
        $user = $request->user();

        if ($user->is_founder) {
            return response()->json(['message' => 'You already own the Founder Beta Pack.'], 422);
        }
        if (! self::available($user)) {
            return response()->json(['message' => 'The Founder Beta Pack is not currently available.'], 422);
        }
        if (! config('services.stripe.secret')) {
            return response()->json([
                'message' => 'Stripe is not configured yet. Add STRIPE_KEY/STRIPE_SECRET to .env to enable purchases.',
            ], 500);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => 'Founder Beta Pack',
                        'description' => 'Exclusive Founder title, badge, avatar frame, banner, and gold name color, plus 5,000 gems — never sold again.',
                    ],
                    'unit_amount' => self::PRICE_CENTS,
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $user->id,
            'metadata' => ['founder_pack' => '1', 'user_id' => (string) $user->id],
            'automatic_tax' => ['enabled' => true],
            'consent_collection' => ['terms_of_service' => 'required'],
            'custom_text' => [
                'terms_of_service_acceptance' => [
                    'message' => 'All Solyx purchases are final. Content, values, and benefits can change as the game develops, and no refunds are issued if that happens or if related data is lost.',
                ],
            ],
            'success_url' => config('app.url').'/vip?checkout=success',
            'cancel_url' => config('app.url').'/vip?checkout=cancelled',
        ]);

        return response()->json(['checkout_url' => $session->url]);
    }

    /** Grants the bundle + records the account as a Founder — called from StoreController::webhook()
     * on checkout.session.completed, mirroring fulfilVipLifetime()'s shape. Idempotent on is_founder. */
    public static function fulfil(object $session): void
    {
        $user = User::find($session->metadata['user_id'] ?? null);
        if (! $user || $user->is_founder) {
            return;
        }

        $character = $user->character;
        if ($character) {
            $cosmeticIds = \App\Models\Cosmetic::whereIn('key', self::COSMETIC_KEYS)->pluck('id', 'key');
            foreach ($cosmeticIds as $cosmeticId) {
                \App\Models\CharacterCosmetic::firstOrCreate(
                    ['character_id' => $character->id, 'cosmetic_id' => $cosmeticId],
                    ['unlocked_at' => now()]
                );
            }

            $user->increment('gems', self::GEM_BONUS);
            \App\Models\GemLedger::log($user, self::GEM_BONUS, 'purchase:founder_pack', $character);
        }

        $user->is_founder = true;
        $user->founder_purchased_at = now();
        $user->save();

        Purchase::firstOrCreate(
            ['stripe_session_id' => $session->id],
            ['user_id' => $user->id, 'sku' => 'founder_pack', 'amount_cents' => $session->amount_total ?? self::PRICE_CENTS, 'status' => 'completed']
        );
    }
}
