<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\Purchase;
use App\Services\AutoBattleService;
use App\Services\AutoGatherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Webhook;

class StoreController extends Controller
{
    /** SKU catalog — cents + gem/effect payload. Not DB-backed; edit here to change pricing. Keys are named
     * for the actual total 'gems' amount each pack grants (matching what fulfil() actually credits) — they
     * used to be named after stale numbers (gems_340/900/2000/8000) that didn't match any field in the
     * pack itself, which is exactly the kind of drift this naming convention is meant to prevent. */
    private const GEM_PACKS = [
        'gems_1000' => ['label' => '1000 Gems', 'price_cents' => 499, 'gems' => 1000, 'bonus' => 100],
        'gems_2000' => ['label' => '2000 Gems', 'price_cents' => 999, 'gems' => 2000, 'bonus' => 200],
        'gems_4000' => ['label' => '4000 Gems', 'price_cents' => 1999, 'gems' => 4000, 'bonus' => 400],
        'gems_14000' => ['label' => '14000 Gems', 'price_cents' => 6999, 'gems' => 14000, 'bonus' => 1400],
    ];

    private const OTHER_SKUS = [
        'remove_ads' => ['label' => 'Remove Ads', 'price_cents' => 499],
        // Same price as the entry 'gems_1000' pack (€4.99) — matches BattlePassController::PREMIUM_GEM_COST
        // (1000 gems) so the cash and gem prices are the same offer, not two arbitrary numbers.
        'pass_ashfall' => ['label' => 'Ashfall Season Pass (Premium)', 'price_cents' => 499],
        // Overnight passes — 8 hours for €0.99 each, cash-only (no gem equivalent at this tier).
        'auto_battle_480' => ['label' => '8 Hour Auto-Attack (Overnight)', 'price_cents' => 99],
        'auto_gather_480' => ['label' => '8 Hour Auto-Gather (Overnight)', 'price_cents' => 99],
    ];

    public function gems(Request $request)
    {
        abort_unless(FeatureFlag::gate('gem_store', $request->user()), 403, 'The Gem Store is not currently available.');

        return response()->json(['packs' => self::GEM_PACKS, 'remove_ads' => self::OTHER_SKUS['remove_ads']]);
    }

    /** Everywhere else in the game gems can be spent — purely a signpost catalog, pointing each
     * category to the page that actually owns its purchase flow (via `route`). Auto-Attack and
     * Auto-Gather aren't listed here since the Gem Store already has its own interactive cards
     * for those two further up the page. */
    public function gemSinks(Request $request)
    {
        return response()->json(['categories' => [
            [
                'key' => 'character_slots',
                'label' => 'Character Slots',
                'glyph' => '➕',
                'desc' => 'Unlock extra character slots on your account.',
                'route' => '/characters',
            ],
            [
                'key' => 'pets',
                'label' => 'Companion Pets',
                'glyph' => '🐾',
                'desc' => 'Unlock companion pets with passive combat, gathering, or crafting bonuses.',
                'route' => '/pets',
            ],
            [
                'key' => 'shop_items',
                'label' => 'Shop Gear & Consumables',
                'glyph' => '🛒',
                'desc' => 'Premium gear and consumables bought directly with gems.',
                'route' => '/shop',
            ],
            [
                'key' => 'battle_pass',
                'label' => 'Battle Pass',
                'glyph' => '🎖',
                'desc' => 'Unlock the premium reward track for the current season.',
                'route' => '/battle-pass',
            ],
            [
                'key' => 'cosmetics',
                'label' => 'Titles, Colors, Banners & Icons',
                'glyph' => '✨',
                'desc' => 'Prestige titles, name colors, profile banners, and icons.',
                'route' => '/profile',
            ],
        ]]);
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
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => $catalogEntry['label']],
                    'unit_amount' => $catalogEntry['price_cents'],
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $request->user()->id,
            'metadata' => ['sku' => $sku, 'user_id' => $request->user()->id],
            'automatic_tax' => ['enabled' => true],
            // Forces a Stripe-hosted "I agree to the Terms of Service" checkbox before the buyer can pay —
            // Stripe links it to the Terms of service URL set in the Dashboard (Settings > Business,
            // "Terms of service"), which must point at /terms. Purchases are final per that page's no-refund
            // clause, so getting explicit acceptance at checkout backs up the in-app disclosure on disputes.
            'consent_collection' => ['terms_of_service' => 'required'],
            'custom_text' => [
                'terms_of_service_acceptance' => [
                    'message' => 'All Solyx purchases are final. Content, values, and benefits can change as the game develops, and no refunds are issued if that happens or if related data is lost.',
                ],
            ],
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

            if (isset($session->metadata['vip_lifetime'])) {
                $this->fulfilVipLifetime($session);
            } elseif (isset($session->metadata['founder_pack'])) {
                FounderPackController::fulfil($session);
                $this->recordSpend($session->metadata['user_id'] ?? null, $session->amount_total ?? 0);
            } elseif (isset($session->metadata['vip_tier'])) {
                $this->fulfilVipSubscription($session);
            } else {
                $purchase = Purchase::where('stripe_session_id', $session->id)->first();
                if ($purchase && $purchase->status !== 'completed') {
                    $purchase->update(['status' => 'completed']);
                    $this->fulfil($purchase);
                    $this->recordSpend($purchase->user_id, $purchase->amount_cents);
                }
            }
        }

        if ($event->type === 'customer.subscription.deleted') {
            $subscription = $event->data->object;
            \App\Models\User::where('id', $subscription->metadata['user_id'] ?? null)
                ->update(['vip_tier' => 'none', 'vip_expires_at' => null, 'stripe_subscription_id' => null]);
        }

        // Renews vip_expires_at every billing cycle — without this, VIP would silently expire after
        // the first month even though Stripe keeps charging the card, since fulfilVipSubscription()
        // below only ever runs once, at initial checkout.
        if (in_array($event->type, ['invoice.paid', 'invoice.payment_succeeded'], true)) {
            $this->renewVipFromInvoice($event->data->object);
        }

        return response()->json(['received' => true]);
    }

    private function fulfilVipSubscription(object $session): void
    {
        $user = \App\Models\User::find($session->metadata['user_id'] ?? null);
        if (! $user) {
            return;
        }

        $period = $session->metadata['vip_period'] ?? 'month';

        // User's #[Fillable] attribute only allows name/email/password through mass-assignment —
        // ->update() here would silently no-op (confirmed: same landmine as the is_tester bug).
        // Direct property assignment bypasses the guard.
        $user->vip_tier = $session->metadata['vip_tier'];
        $user->vip_expires_at = $period === 'year' ? now()->addYear() : now()->addMonth();
        // Stashed so a later tier switch can update this same subscription in Stripe (proration)
        // instead of starting a second, separately-billed one — see VipController::subscribe().
        $user->stripe_subscription_id = is_string($session->subscription ?? null) ? $session->subscription : ($session->subscription->id ?? null);
        $user->save();

        $this->recordSpend($user->id, $session->amount_total ?? 0);
        Purchase::firstOrCreate(
            ['stripe_session_id' => $session->id],
            ['user_id' => $user->id, 'sku' => "vip_{$user->vip_tier}_{$period}", 'amount_cents' => $session->amount_total ?? 0, 'status' => 'completed']
        );
    }

    /** One-time Lifetime VIP purchase — no subscription, no recurring billing, no expiry. */
    private function fulfilVipLifetime(object $session): void
    {
        $user = \App\Models\User::find($session->metadata['user_id'] ?? null);
        if (! $user || $user->vip_lifetime) {
            return;
        }

        $user->vip_tier = 'diamond';
        $user->vip_lifetime = true;
        $user->vip_expires_at = null;
        $user->save();

        $this->recordSpend($user->id, $session->amount_total ?? 0);
        Purchase::firstOrCreate(
            ['stripe_session_id' => $session->id],
            ['user_id' => $user->id, 'sku' => 'vip_lifetime', 'amount_cents' => $session->amount_total ?? 0, 'status' => 'completed']
        );
    }

    /** Running lifetime real-money total per account — every checkout.session.completed and recurring
     * invoice.paid event feeds this. Currently only consumed by FounderPackController's Hall of Founders
     * ranking (spend order, amount itself never shown), but it's an account-wide total, not scoped to
     * any one purchase type. */
    private function recordSpend(?string $userId, int $amountCents): void
    {
        if (! $userId || $amountCents <= 0) {
            return;
        }

        \App\Models\User::where('id', $userId)->increment('lifetime_spend_cents', $amountCents);
    }

    /** Handles both the classic flat `invoice.subscription` shape and the newer nested
     * `invoice.parent.subscription_details.subscription` shape, since API-version upgrades moved this field. */
    private function renewVipFromInvoice(object $invoice): void
    {
        $subscriptionId = $invoice->subscription ?? $invoice->parent->subscription_details->subscription ?? null;
        if (! $subscriptionId) {
            return;
        }
        $subscriptionId = is_string($subscriptionId) ? $subscriptionId : $subscriptionId->id;

        // The first invoice on a new subscription is already covered by checkout.session.completed —
        // re-granting here would double up the first month if both events arrive close together.
        if (($invoice->billing_reason ?? null) === 'subscription_create') {
            return;
        }

        if (! config('services.stripe.secret')) {
            return;
        }

        $stripe = new StripeClient(config('services.stripe.secret'));
        $subscription = $stripe->subscriptions->retrieve($subscriptionId);
        $userId = $subscription->metadata['user_id'] ?? null;
        $vipTier = $subscription->metadata['vip_tier'] ?? null;
        if (! $userId || ! $vipTier) {
            return;
        }

        $user = \App\Models\User::find($userId);
        if (! $user) {
            return;
        }

        $period = $subscription->metadata['vip_period'] ?? 'month';

        $user->vip_tier = $vipTier;
        $user->vip_expires_at = $period === 'year' ? now()->addYear() : now()->addMonth();
        $user->save();

        $this->recordSpend($userId, $invoice->amount_paid ?? 0);
        // Invoices (not checkout sessions) drive renewals, so they need their own unique key —
        // prefixed to guarantee no collision with a 'cs_...' checkout session id.
        Purchase::firstOrCreate(
            ['stripe_session_id' => 'inv_'.$invoice->id],
            ['user_id' => $userId, 'sku' => "vip_{$vipTier}_renewal", 'amount_cents' => $invoice->amount_paid ?? 0, 'status' => 'completed']
        );
    }

    private function fulfil(Purchase $purchase): void
    {
        $user = $purchase->user;
        $character = $user->character;

        if (isset(self::GEM_PACKS[$purchase->sku]) && $character) {
            $gems = self::GEM_PACKS[$purchase->sku]['gems'];
            $user->increment('gems', $gems);
            GemLedger::log($user, $gems, "purchase:{$purchase->sku}", $character);
        } elseif ($purchase->sku === 'remove_ads') {
            $user->ads_removed = true;
            $user->save();
        } elseif ($purchase->sku === 'pass_ashfall' && $character) {
            $character->battlePasses()->updateOrCreate(['season' => 'ashfall'], ['premium' => true]);
        } elseif ($purchase->sku === 'auto_battle_480' && $character) {
            (new AutoBattleService())->extend($character, 480);
        } elseif ($purchase->sku === 'auto_gather_480' && $character) {
            (new AutoGatherService())->extendFromPurchase($character, 480);
        }
    }
}
