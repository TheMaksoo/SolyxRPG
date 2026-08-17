<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Stripe\StripeClient;

class VipController extends Controller
{
    /** monthly price in cents per tier; bonus character slots come from User::VIP_TIER_SLOTS */
    private const TIERS = [
        'bronze' => ['label' => 'Bronze Rank', 'price_cents' => 299],
        'gold' => ['label' => 'Gold Rank', 'price_cents' => 499],
        'diamond' => ['label' => 'Diamond Rank', 'price_cents' => 999],
    ];

    /** Annual price per tier — ~25% off 12x the monthly price, rounded to a clean price point. */
    private const YEARLY_PRICE_CENTS = [
        'bronze' => 2699,
        'gold' => 4499,
        'diamond' => 8999,
    ];

    /** One-time purchase, no recurring billing — grants Diamond-tier perks forever. Priced at roughly
     * 20x the Diamond monthly rate, in line with how other subscription games price a "lifetime" tier. */
    private const LIFETIME_PRICE_CENTS = 19999;
    private const LIFETIME_GRANTS_TIER = 'diamond';

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(FeatureFlag::gate('vip', $user), 403, 'Premium is not currently available.');

        $tiers = collect(self::TIERS)
            ->map(fn ($tier, $key) => [
                ...$tier,
                'yearly_price_cents' => self::YEARLY_PRICE_CENTS[$key],
                'slots' => User::VIP_TIER_SLOTS[$key],
                'luck_bonus' => User::VIP_TIER_LUCK[$key],
                'regen_flat_bonus' => User::VIP_TIER_REGEN_FLAT[$key],
                'regen_pct_bonus' => User::VIP_TIER_REGEN_PCT[$key],
                'gold_xp_pct_bonus' => User::VIP_TIER_GOLD_XP_PCT[$key],
                'craft_speed_pct_bonus' => User::VIP_TIER_CRAFT_SPEED_PCT[$key],
                'energy_flat_bonus' => User::VIP_TIER_ENERGY_FLAT[$key],
                'energy_pct_bonus' => User::VIP_TIER_ENERGY_PCT[$key],
                'craft_queue_bonus' => User::VIP_TIER_CRAFT_QUEUE_BONUS[$key],
                'monthly_gems' => User::VIP_TIER_MONTHLY_GEMS[$key],
                'pet_slots' => User::VIP_TIER_PET_SLOTS[$key],
                'market_listing_bonus' => User::VIP_TIER_MARKET_LISTINGS[$key],
                'pvp_bonus_attempts' => User::VIP_TIER_PVP_ATTEMPTS[$key],
                'dungeon_bonus_attempts' => User::VIP_TIER_DUNGEON_ATTEMPTS[$key],
                'auto_pass_pct_bonus' => User::VIP_TIER_AUTO_PASS_PCT[$key],
                'daily_gem_bonus' => User::VIP_TIER_DAILY_GEM_BONUS[$key],
                'market_fee_reduction_pct' => User::VIP_TIER_MARKET_FEE_REDUCTION_PCT[$key],
                'gather_speed_pct_bonus' => User::VIP_TIER_GATHER_SPEED_PCT[$key],
                'gather_yield_pct_bonus' => User::VIP_TIER_GATHER_YIELD_PCT[$key],
                'lootbox_extra_slots' => User::VIP_TIER_LOOTBOX_SLOTS[$key],
                'lootbox_time_reduction_pct' => User::VIP_TIER_LOOTBOX_TIME_REDUCTION_PCT[$key],
                'bp_xp_pct_bonus' => User::VIP_TIER_BP_XP_PCT[$key],
                'tame_roster_cap' => User::VIP_TIER_TAME_ROSTER_CAP[$key],
                'tame_success_bonus_pct' => User::VIP_TIER_TAME_BONUS[$key],
            ])
            ->all();

        return response()->json([
            // Expiry-aware — a lapsed subscription's vip_tier column can sit stale until the next
            // webhook touches it, so this always reflects whether VIP is actually active right now.
            'vip_tier' => $user->hasActiveVip() ? $user->vip_tier : 'none',
            'vip_expires_at' => $user->vip_expires_at,
            'vip_cancel_at_period_end' => $user->vip_cancel_at_period_end,
            'vip_lifetime' => $user->vip_lifetime,
            'has_stripe_subscription' => $user->stripe_subscription_id !== null,
            'tiers' => $tiers,
            'lifetime' => [
                'label' => 'Lifetime Rank',
                'price_cents' => self::LIFETIME_PRICE_CENTS,
                'grants_tier' => self::LIFETIME_GRANTS_TIER,
            ],
        ]);
    }

    /** Stops future billing without touching current access — perks and vip_expires_at stay exactly as
     * they are; Stripe just won't renew the subscription when that date arrives (cancel_at_period_end,
     * not an immediate cancel/delete). The `customer.subscription.deleted` webhook then does the actual
     * downgrade once the period genuinely ends — see StoreController::webhook(). */
    public function cancel(Request $request)
    {
        $user = $request->user();

        if (! $user->stripe_subscription_id) {
            return response()->json(['message' => 'No active subscription to cancel.'], 422);
        }
        if (! config('services.stripe.secret')) {
            return response()->json(['message' => 'Stripe is not configured.'], 500);
        }

        try {
            $stripe = new StripeClient(config('services.stripe.secret'));
            $stripe->subscriptions->update($user->stripe_subscription_id, ['cancel_at_period_end' => true]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Could not cancel: '.$e->getMessage()], 500);
        }

        $user->vip_cancel_at_period_end = true;
        $user->save();

        return response()->json(['vip_cancel_at_period_end' => true, 'vip_expires_at' => $user->vip_expires_at]);
    }

    /** Undoes a pending cancellation on the SAME subscription — since it was never actually deleted
     * (see cancel() above), this just flips cancel_at_period_end back off. No new checkout, no charge
     * now: billing simply resumes as normal on the existing subscription's next renewal date. */
    public function resume(Request $request)
    {
        $user = $request->user();

        if (! $user->stripe_subscription_id || ! $user->vip_cancel_at_period_end) {
            return response()->json(['message' => 'No pending cancellation to undo.'], 422);
        }
        if (! config('services.stripe.secret')) {
            return response()->json(['message' => 'Stripe is not configured.'], 500);
        }

        try {
            $stripe = new StripeClient(config('services.stripe.secret'));
            $stripe->subscriptions->update($user->stripe_subscription_id, ['cancel_at_period_end' => false]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Could not resume: '.$e->getMessage()], 500);
        }

        $user->vip_cancel_at_period_end = false;
        $user->save();

        return response()->json(['vip_cancel_at_period_end' => false]);
    }

    /** Starts a Stripe Billing subscription — this one genuinely requires Stripe keys, unlike the gem-cost purchases. */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'tier' => ['required', Rule::in(array_keys(self::TIERS))],
            'period' => ['nullable', Rule::in(['month', 'year'])],
        ]);
        $period = $data['period'] ?? 'month';

        if (! config('services.stripe.secret')) {
            return response()->json([
                'message' => 'Stripe is not configured yet. Add STRIPE_KEY/STRIPE_SECRET to .env to enable subscriptions.',
            ], 500);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));
        $user = $request->user();
        $tier = self::TIERS[$data['tier']];
        $priceCents = $period === 'year' ? self::YEARLY_PRICE_CENTS[$data['tier']] : $tier['price_cents'];

        // Already on a different active tier — switch the existing Stripe subscription in place
        // (with proration) instead of starting a second, separately-billed subscription.
        if ($user->hasActiveVip() && $user->stripe_subscription_id) {
            try {
                $subscription = $stripe->subscriptions->retrieve($user->stripe_subscription_id);

                // Subscription item updates only accept an existing `price` id — unlike Checkout Sessions,
                // `price_data.product_data` (inline product creation) isn't a valid param here. Price
                // creation itself DOES support inline product_data, so create the Price first, then
                // reference it by id.
                $price = $stripe->prices->create([
                    // Stripe locks a subscription's currency at creation — reusing whatever it already
                    // bills in here (not the user's *current* currency setting) avoids a rejected update
                    // if they changed their preference after subscribing.
                    'currency' => $subscription->currency,
                    'unit_amount' => $priceCents,
                    'recurring' => ['interval' => $period],
                    'product_data' => ['name' => $tier['label']],
                ]);

                $stripe->subscriptions->update($user->stripe_subscription_id, [
                    'items' => [[
                        'id' => $subscription->items->data[0]->id,
                        'price' => $price->id,
                    ]],
                    // 'always_invoice' bills the prorated difference immediately (charging the customer's
                    // saved card right away) instead of just tacking a proration line onto next month's
                    // invoice — an upgrade should cost the difference now, not be billed retroactively later.
                    'proration_behavior' => 'always_invoice',
                    'metadata' => ['vip_tier' => $data['tier'], 'vip_period' => $period, 'user_id' => (string) $user->id],
                ]);
            } catch (\Exception $e) {
                return response()->json(['message' => 'Could not switch plans: '.$e->getMessage()], 500);
            }

            $user->vip_tier = $data['tier'];
            $user->save();

            return response()->json(['switched' => true, 'vip_tier' => $data['tier']]);
        }

        $sessionParams = [
            'mode' => 'subscription',
            // No 'payment_method_types' here — omitting it lets Checkout auto-offer whatever payment
            // methods are enabled in the Stripe Dashboard (cards, wallets, local methods), same as the
            // gem-store checkout in StoreController. Explicitly listing ['card'] would restrict to card-only.
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => $tier['label'].($period === 'year' ? ' (Yearly)' : '')],
                    'unit_amount' => $priceCents,
                    'recurring' => ['interval' => $period],
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $request->user()->id,
            'metadata' => ['vip_tier' => $data['tier'], 'vip_period' => $period, 'user_id' => $request->user()->id],
            // Checkout Session metadata does NOT propagate to the underlying Subscription object —
            // it has to be set here too, or the renewal (invoice.paid) and cancellation
            // (customer.subscription.deleted) webhook handlers have no way to identify the user.
            'subscription_data' => [
                'metadata' => ['vip_tier' => $data['tier'], 'vip_period' => $period, 'user_id' => $request->user()->id],
            ],
            'automatic_tax' => ['enabled' => true],
            'consent_collection' => ['terms_of_service' => 'required'],
            'custom_text' => [
                'terms_of_service_acceptance' => [
                    'message' => 'All Solyx purchases are final. Content, values, and benefits can change as the game develops, and no refunds are issued if that happens or if related data is lost.',
                ],
            ],
            'success_url' => config('app.url').'/vip?checkout=success',
            'cancel_url' => config('app.url').'/vip?checkout=cancelled',
        ];

        $session = $stripe->checkout->sessions->create($sessionParams);

        return response()->json(['checkout_url' => $session->url]);
    }

    /** One-time, non-recurring purchase — grants Diamond-tier perks forever. No subscription is ever
     * created, so cancel()/resume() simply don't apply (nothing to cancel). */
    public function purchaseLifetime(Request $request)
    {
        $user = $request->user();

        if ($user->vip_lifetime) {
            return response()->json(['message' => 'You already own Lifetime Rank.'], 422);
        }
        if (! config('services.stripe.secret')) {
            return response()->json([
                'message' => 'Stripe is not configured yet. Add STRIPE_KEY/STRIPE_SECRET to .env to enable purchases.',
            ], 500);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            // Same reasoning as subscribe() above — let Checkout auto-offer whatever's enabled in the
            // Dashboard instead of restricting to card-only.
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => 'Lifetime Rank'],
                    'unit_amount' => self::LIFETIME_PRICE_CENTS,
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $user->id,
            'metadata' => ['vip_lifetime' => '1', 'user_id' => (string) $user->id],
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
}
