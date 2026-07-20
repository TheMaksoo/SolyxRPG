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
        'bronze' => ['label' => 'Bronze VIP', 'price_cents' => 299],
        'gold' => ['label' => 'Gold VIP', 'price_cents' => 499],
        'diamond' => ['label' => 'Diamond VIP', 'price_cents' => 999],
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(FeatureFlag::gate('vip', $user), 403, 'VIP is not currently available.');

        $tiers = collect(self::TIERS)
            ->map(fn ($tier, $key) => [
                ...$tier,
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
            ])
            ->all();

        return response()->json([
            // Expiry-aware — a lapsed subscription's vip_tier column can sit stale until the next
            // webhook touches it, so this always reflects whether VIP is actually active right now.
            'vip_tier' => $user->hasActiveVip() ? $user->vip_tier : 'none',
            'vip_expires_at' => $user->vip_expires_at,
            'tiers' => $tiers,
        ]);
    }

    /** Starts a Stripe Billing subscription — this one genuinely requires Stripe keys, unlike the gem-cost purchases. */
    public function subscribe(Request $request)
    {
        $data = $request->validate(['tier' => ['required', Rule::in(array_keys(self::TIERS))]]);

        if (! config('services.stripe.secret')) {
            return response()->json([
                'message' => 'Stripe is not configured yet. Add STRIPE_KEY/STRIPE_SECRET to .env to enable subscriptions.',
            ], 500);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));
        $user = $request->user();
        $tier = self::TIERS[$data['tier']];

        // Already on a different active tier — switch the existing Stripe subscription in place
        // (with proration) instead of starting a second, separately-billed subscription.
        if ($user->hasActiveVip() && $user->stripe_subscription_id) {
            try {
                $subscription = $stripe->subscriptions->retrieve($user->stripe_subscription_id);
                $stripe->subscriptions->update($user->stripe_subscription_id, [
                    'items' => [[
                        'id' => $subscription->items->data[0]->id,
                        'price_data' => [
                            'currency' => 'usd',
                            'product_data' => ['name' => $tier['label']],
                            'unit_amount' => $tier['price_cents'],
                            'recurring' => ['interval' => 'month'],
                        ],
                    ]],
                    'proration_behavior' => 'create_prorations',
                    'metadata' => ['vip_tier' => $data['tier'], 'user_id' => (string) $user->id],
                ]);
            } catch (\Exception $e) {
                return response()->json(['message' => 'Could not switch plans: '.$e->getMessage()], 500);
            }

            $user->vip_tier = $data['tier'];
            $user->save();

            return response()->json(['switched' => true, 'vip_tier' => $data['tier']]);
        }

        $session = $stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $tier['label']],
                    'unit_amount' => $tier['price_cents'],
                    'recurring' => ['interval' => 'month'],
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $request->user()->id,
            'metadata' => ['vip_tier' => $data['tier'], 'user_id' => $request->user()->id],
            // Checkout Session metadata does NOT propagate to the underlying Subscription object —
            // it has to be set here too, or the renewal (invoice.paid) and cancellation
            // (customer.subscription.deleted) webhook handlers have no way to identify the user.
            'subscription_data' => [
                'metadata' => ['vip_tier' => $data['tier'], 'user_id' => $request->user()->id],
            ],
            'success_url' => config('app.url').'/vip?checkout=success',
            'cancel_url' => config('app.url').'/vip?checkout=cancelled',
        ]);

        return response()->json(['checkout_url' => $session->url]);
    }
}
