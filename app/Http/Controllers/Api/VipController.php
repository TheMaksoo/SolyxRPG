<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'vip_tier' => $user->vip_tier,
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
                'message' => 'Stripe is not configured yet. Add STRIPE_KEY/STRIPE_SECRET to .env, and create a recurring Price for each VIP tier in the Stripe dashboard, to enable subscriptions.',
            ], 500);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));
        $tier = self::TIERS[$data['tier']];

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
            'success_url' => config('app.url').'/vip?checkout=success',
            'cancel_url' => config('app.url').'/vip?checkout=cancelled',
        ]);

        return response()->json(['checkout_url' => $session->url]);
    }
}
