<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GemLedger;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Webhook;

class StoreController extends Controller
{
    /** SKU catalog — cents + gem/effect payload. Not DB-backed; edit here to change pricing. */
    private const GEM_PACKS = [
        'gems_340' => ['label' => '340 Gems', 'price_cents' => 499, 'gems' => 340],
        'gems_900' => ['label' => '900 Gems', 'price_cents' => 999, 'gems' => 900],
        'gems_2000' => ['label' => '2000 Gems', 'price_cents' => 1999, 'gems' => 2000],
        'gems_8000' => ['label' => '8000 Gems', 'price_cents' => 6999, 'gems' => 8000],
    ];

    private const OTHER_SKUS = [
        'remove_ads' => ['label' => 'Remove Ads', 'price_cents' => 499],
        'pass_ashfall' => ['label' => 'Ashfall Season Pass (Premium)', 'price_cents' => 999],
    ];

    public function gems()
    {
        return response()->json(['packs' => self::GEM_PACKS]);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate(['sku' => ['required', 'string']]);
        $sku = $data['sku'];
        $catalogEntry = self::GEM_PACKS[$sku] ?? self::OTHER_SKUS[$sku] ?? null;

        if (! $catalogEntry) {
            return response()->json(['message' => 'Unknown SKU.'], 422);
        }

        if (! config('services.stripe.secret')) {
            return response()->json([
                'message' => 'Stripe is not configured yet. Add STRIPE_KEY/STRIPE_SECRET to .env to enable checkout.',
            ], 500);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $catalogEntry['label']],
                    'unit_amount' => $catalogEntry['price_cents'],
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $request->user()->id,
            'metadata' => ['sku' => $sku, 'user_id' => $request->user()->id],
            'success_url' => config('app.url').'/gem-store?checkout=success',
            'cancel_url' => config('app.url').'/gem-store?checkout=cancelled',
        ]);

        Purchase::create([
            'user_id' => $request->user()->id,
            'sku' => $sku,
            'amount_cents' => $catalogEntry['price_cents'],
            'status' => 'pending',
            'stripe_session_id' => $session->id,
        ]);

        return response()->json(['checkout_url' => $session->url]);
    }

    /** Stripe webhook — the only place purchases are ever credited. Idempotent on stripe_session_id. */
    public function webhook(Request $request)
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret) {
            return response()->json(['message' => 'Webhook secret not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                $secret
            );
        } catch (\Exception $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            if (isset($session->metadata['vip_tier'])) {
                $this->fulfilVipSubscription($session);
            } else {
                $purchase = Purchase::where('stripe_session_id', $session->id)->first();
                if ($purchase && $purchase->status !== 'completed') {
                    $purchase->update(['status' => 'completed']);
                    $this->fulfil($purchase);
                }
            }
        }

        if ($event->type === 'customer.subscription.deleted') {
            $subscription = $event->data->object;
            \App\Models\User::where('id', $subscription->metadata['user_id'] ?? null)
                ->update(['vip_tier' => 'none', 'vip_expires_at' => null]);
        }

        return response()->json(['received' => true]);
    }

    private function fulfilVipSubscription(object $session): void
    {
        $user = \App\Models\User::find($session->metadata['user_id'] ?? null);
        if (! $user) {
            return;
        }

        $user->update([
            'vip_tier' => $session->metadata['vip_tier'],
            'vip_expires_at' => now()->addMonth(),
        ]);
    }

    private function fulfil(Purchase $purchase): void
    {
        $user = $purchase->user;
        $character = $user->character;

        if (isset(self::GEM_PACKS[$purchase->sku]) && $character) {
            $gems = self::GEM_PACKS[$purchase->sku]['gems'];
            $character->increment('gems', $gems);
            GemLedger::create(['character_id' => $character->id, 'delta' => $gems, 'reason' => "purchase:{$purchase->sku}", 'created_at' => now()]);
        } elseif ($purchase->sku === 'remove_ads') {
            $user->update(['ads_removed' => true]);
        } elseif ($purchase->sku === 'pass_ashfall' && $character) {
            $character->battlePasses()->updateOrCreate(['season' => 'ashfall'], ['premium' => true]);
        }
    }
}
