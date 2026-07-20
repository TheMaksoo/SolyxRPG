<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cosmetic;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\Item;
use App\Models\Pet;
use App\Models\Purchase;
use App\Services\AutoBattleService;
use App\Services\AutoGatherService;
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
        'pass_ashfall' => ['label' => 'Ashfall Season Pass (Premium)', 'price_cents' => 599],
        // The only Auto-Attack duration sold for real money — 15/30 min stay gems-only (see AutoBattleService).
        'auto_battle_60' => ['label' => '1 Hour Auto-Attack', 'price_cents' => 100],
    ];

    public function gems(Request $request)
    {
        abort_unless(FeatureFlag::gate('gem_store', $request->user()), 403, 'The Gem Store is not currently available.');

        return response()->json(['packs' => self::GEM_PACKS, 'remove_ads' => self::OTHER_SKUS['remove_ads']]);
    }

    /** Everywhere in the game gems can be spent, gathered into one browsable catalog — purely informational,
     * each category's actual purchase still happens on its own page (linked via `route`). */
    public function gemSinks(Request $request)
    {
        $character = $request->user()->character;
        $ownedPetIds = $character ? $character->pets()->pluck('pet_id')->all() : [];
        $ownedCosmeticIds = $character ? $character->cosmetics()->pluck('cosmetic_id')->all() : [];

        $autoBattle = new AutoBattleService();
        $autoGather = new AutoGatherService();

        return response()->json(['categories' => [
            [
                'key' => 'auto_battle',
                'label' => 'Auto-Attack Passes',
                'glyph' => '⚔',
                'desc' => "Fight monsters unattended while you're away.",
                'route' => '/battle',
                'items' => collect($autoBattle->costs())->map(fn ($cost, $min) => ['label' => "{$min} min", 'cost' => $cost])->values(),
            ],
            [
                'key' => 'auto_gather',
                'label' => 'Auto-Gather Passes',
                'glyph' => '⛏',
                'desc' => 'Gather resources unattended — same price as Auto-Attack, double the duration.',
                'route' => '/trade-skills',
                'items' => collect($autoGather->costs())->map(fn ($cost, $min) => ['label' => "{$min} min", 'cost' => $cost])->values(),
            ],
            [
                'key' => 'character_slots',
                'label' => 'Character Slots',
                'glyph' => '➕',
                'desc' => 'Unlock extra character slots on your account.',
                'route' => '/characters',
                'items' => collect(CharacterController::GEM_SLOT_COSTS)->map(fn ($cost, $tier) => ['label' => 'Slot '.($tier + 1), 'cost' => $cost])->values(),
            ],
            [
                'key' => 'pets',
                'label' => 'Companion Pets',
                'glyph' => '🐾',
                'desc' => 'Unlock companion pets with passive combat, gathering, or crafting bonuses.',
                'route' => '/pets',
                'items' => Pet::where('enabled', true)->orderBy('unlock_gems')->get()->map(fn (Pet $pet) => [
                    'label' => "{$pet->glyph} {$pet->name}",
                    'cost' => $pet->unlock_gems,
                    'owned' => in_array($pet->id, $ownedPetIds, true),
                ]),
            ],
            [
                'key' => 'shop_items',
                'label' => 'Shop Gear & Consumables',
                'glyph' => '🛒',
                'desc' => 'Premium gear, consumables, and cosmetics bought directly with gems.',
                'route' => '/shop',
                'items' => Item::where('enabled', true)->whereNotNull('price_gems')->orderBy('price_gems')->get()
                    ->map(fn (Item $item) => ['label' => "{$item->glyph} {$item->name}", 'cost' => $item->price_gems]),
            ],
            [
                'key' => 'battle_pass',
                'label' => 'Battle Pass',
                'glyph' => '🎖',
                'desc' => 'Unlock the premium reward track for the current season.',
                'route' => '/battle-pass',
                'items' => [['label' => 'Premium Track', 'cost' => BattlePassController::PREMIUM_GEM_COST]],
            ],
            [
                'key' => 'cosmetics',
                'label' => 'Titles, Colors, Banners & Icons',
                'glyph' => '✨',
                'desc' => 'Prestige titles, name colors, profile banners, and icons — gem-purchase only (most other titles are earned free by completing their matching quest).',
                'route' => '/profile',
                'items' => Cosmetic::where('enabled', true)->where('cost_gems', '>', 0)->whereNull('unlock_quest_key')->orderBy('type')->orderBy('cost_gems')->get()
                    ->map(fn (Cosmetic $c) => [
                        'label' => "{$c->name} (".ucfirst($c->type).')',
                        'cost' => $c->cost_gems,
                        'owned' => in_array($c->id, $ownedCosmeticIds, true),
                    ]),
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
            'payment_method_types' => ['card'],
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

        // User's #[Fillable] attribute only allows name/email/password through mass-assignment —
        // ->update() here would silently no-op (confirmed: same landmine as the is_tester bug).
        // Direct property assignment bypasses the guard.
        $user->vip_tier = $session->metadata['vip_tier'];
        $user->vip_expires_at = now()->addMonth();
        // Stashed so a later tier switch can update this same subscription in Stripe (proration)
        // instead of starting a second, separately-billed one — see VipController::subscribe().
        $user->stripe_subscription_id = is_string($session->subscription ?? null) ? $session->subscription : ($session->subscription->id ?? null);
        $user->save();
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

        $user->vip_tier = $vipTier;
        $user->vip_expires_at = now()->addMonth();
        $user->save();
    }

    private function fulfil(Purchase $purchase): void
    {
        $user = $purchase->user;
        $character = $user->character;

        if (isset(self::GEM_PACKS[$purchase->sku]) && $character) {
            $gems = self::GEM_PACKS[$purchase->sku]['gems'];
            $character->increment('gems', $gems);
            GemLedger::log($character, $gems, "purchase:{$purchase->sku}");
        } elseif ($purchase->sku === 'remove_ads') {
            $user->ads_removed = true;
            $user->save();
        } elseif ($purchase->sku === 'pass_ashfall' && $character) {
            $character->battlePasses()->updateOrCreate(['season' => 'ashfall'], ['premium' => true]);
        } elseif ($purchase->sku === 'auto_battle_60' && $character) {
            (new AutoBattleService())->extend($character, 60);
        }
    }
}
