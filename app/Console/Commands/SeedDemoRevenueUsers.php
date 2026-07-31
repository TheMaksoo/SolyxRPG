<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Populates the GM Console Revenue dashboard with real, varied Purchase history — founders, VIP
 * subscribers across all three tiers, lifetime VIP, and one-time SKU buyers — spread over the last
 * 60 days so range toggles, trend deltas, and the daily stream chart all have something real to show
 * on a fresh local database. Idempotent: identified by the `demo-revenue-*@solyx.test` email pattern,
 * safe to re-run (existing accounts with purchase history already recorded are skipped, not duplicated). */
class SeedDemoRevenueUsers extends Command
{
    protected $signature = 'demo:seed-revenue-users {--count=50 : How many demo accounts to ensure exist}';

    protected $description = 'Create demo users with characters and real Purchase/VIP/Founder history for testing the Revenue dashboard';

    private const NAMES = [
        'Ashenfall', 'Brakor', 'Cindermoor', 'Duskraven', 'Emberlyn', 'Faelor', 'Grimhollow', 'Hexwing',
        'Ironvale', 'Jarrow', 'Kestrelle', 'Lorathis', 'Morvane', 'Nyxara', 'Oakenshield', 'Pyrrhan',
        'Quorrin', 'Ravenmourn', 'Sylvaris', 'Thornwick', 'Ulvard', 'Veyloth', 'Wraithmere', 'Xanthir',
        'Yseult', 'Zaphrenn', 'Ashgrove', 'Brindlewood', 'Corvusk', 'Draventhal', 'Everhart', 'Frostwyn',
        'Galethorn', 'Hollowmere', 'Ivorclaw', 'Jotungard', 'Korrathis', 'Lunestra', 'Malrik', 'Nightshade',
        'Obsidianne', 'Pellinor', 'Quicksilvern', 'Rowanheart', 'Silverbrook', 'Talonrest', 'Umbraleth',
        'Vexholm', 'Windemere', 'Zephyrion',
    ];

    private const CLASSES = ['warrior', 'mage', 'rogue', 'ranger'];

    private const CLASS_STATS = [
        'warrior' => [230, 12, 90, 14],
        'mage' => [155, 11, 240, 8],
        'rogue' => [180, 13, 120, 10],
        'ranger' => [195, 12, 140, 11],
    ];

    /** VIP monthly prices, mirroring VipController::TIERS — kept here since that const is private. */
    private const VIP_MONTH_CENTS = ['bronze' => 299, 'gold' => 499, 'diamond' => 999];
    private const VIP_YEAR_CENTS = ['bronze' => 2699, 'gold' => 4499, 'diamond' => 8999];
    private const VIP_LIFETIME_CENTS = 19999;
    private const FOUNDER_PACK_CENTS = 1499;

    private const ONE_TIME_SKUS = [
        'gems_1000' => 499, 'gems_2000' => 999, 'gems_4000' => 1999, 'gems_14000' => 6999,
        'pass_ashfall' => 499, 'remove_ads' => 499, 'auto_battle_60' => 99,
    ];

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $created = 0;
        $skipped = 0;

        for ($i = 1; $i <= $count; $i++) {
            $email = sprintf('demo-revenue-%02d@solyx.test', $i);
            $user = User::where('email', $email)->first();

            if (! $user) {
                // User's #[Fillable] guard only allows name/email/password through mass-assignment —
                // email_verified_at has to be set via direct property assignment (see StoreController's
                // recordSpend() comment for the same landmine).
                $user = User::create([
                    'name' => self::NAMES[$i - 1] ?? "DemoPlayer{$i}",
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                ]);
                $user->email_verified_at = now();
                $user->save();
            }

            if (! $user->character) {
                $this->makeCharacter($user, $i);
            }

            if (Purchase::where('user_id', $user->id)->exists()) {
                $skipped++;

                continue;
            }

            $bucket = $i % 10;
            $spendCents = match (true) {
                $bucket === 1 => $this->grantFounder($user),
                $bucket === 2 => $this->grantLifetimeVip($user),
                in_array($bucket, [3, 4, 5], true) => $this->grantVipSubscription($user, $i),
                default => $this->grantOneTimePurchases($user),
            };

            $user->lifetime_spend_cents = $spendCents;
            $user->save();
            $created++;
        }

        $this->info("Demo revenue users: {$created} seeded with fresh purchase history, {$skipped} already had history (untouched).");

        return self::SUCCESS;
    }

    private function makeCharacter(User $user, int $i): void
    {
        $class = self::CLASSES[$i % count(self::CLASSES)];
        [$hp, $atk, $mp, $def] = self::CLASS_STATS[$class];

        $character = Character::create([
            'user_id' => $user->id,
            'name' => self::NAMES[$i - 1] ?? "DemoPlayer{$i}",
            'base_class' => $class,
            'avatar' => '',
            'hp' => $hp,
            'hp_max' => $hp,
            'mana' => $mp,
            'mana_max' => $mp,
            'base_atk' => $atk,
            'base_def' => $def,
            'level' => rand(3, 60),
            'last_active_at' => now()->subDays(rand(0, 12)),
        ]);
        $character->attributes_()->create([]);

        $user->active_character_id = $character->id;
        $user->save();
    }

    /** Backdated over the last 60 days so 7d/30d/season range toggles and trend deltas all have real
     * variation instead of every demo purchase landing on "today". */
    private function randomPastDate(int $maxDaysBack = 60): Carbon
    {
        return now()->subDays(rand(0, $maxDaysBack))->subHours(rand(0, 23))->subMinutes(rand(0, 59));
    }

    private function recordPurchase(User $user, string $sku, int $amountCents, Carbon $at): void
    {
        $purchase = new Purchase([
            'user_id' => $user->id,
            'sku' => $sku,
            'amount_cents' => $amountCents,
            'status' => 'completed',
            'stripe_session_id' => 'demo_'.Str::random(24),
        ]);
        $purchase->timestamps = false;
        $purchase->created_at = $at;
        $purchase->updated_at = $at;
        $purchase->save();
    }

    private function grantFounder(User $user): int
    {
        $at = $this->randomPastDate();
        $this->recordPurchase($user, 'founder_pack', self::FOUNDER_PACK_CENTS, $at);

        $user->is_founder = true;
        $user->founder_purchased_at = $at;

        return self::FOUNDER_PACK_CENTS;
    }

    private function grantLifetimeVip(User $user): int
    {
        $at = $this->randomPastDate();
        $this->recordPurchase($user, 'vip_lifetime', self::VIP_LIFETIME_CENTS, $at);

        $user->vip_tier = 'diamond';
        $user->vip_lifetime = true;
        $user->vip_expires_at = null;

        return self::VIP_LIFETIME_CENTS;
    }

    private function grantVipSubscription(User $user, int $i): int
    {
        $tier = ['bronze', 'gold', 'diamond'][$i % 3];
        $yearly = $i % 2 === 0;
        $at = $this->randomPastDate(45);
        $priceCents = $yearly ? self::VIP_YEAR_CENTS[$tier] : self::VIP_MONTH_CENTS[$tier];

        $this->recordPurchase($user, 'vip_'.$tier.'_'.($yearly ? 'year' : 'month'), $priceCents, $at);

        $spend = $priceCents;

        // A couple of renewal charges for the older monthly subscribers, so the stream isn't just
        // one flat spike — mirrors what StoreController::renewVipFromInvoice() would have recorded.
        if (! $yearly) {
            $renewals = min(2, (int) floor(now()->diffInDays($at) / 30));
            for ($r = 1; $r <= $renewals; $r++) {
                $renewAt = $at->copy()->addDays(30 * $r);
                if ($renewAt->isFuture()) {
                    break;
                }
                $this->recordPurchase($user, 'vip_'.$tier.'_renewal', self::VIP_MONTH_CENTS[$tier], $renewAt);
                $spend += self::VIP_MONTH_CENTS[$tier];
            }
        }

        $user->vip_tier = $tier;
        $user->vip_expires_at = $yearly ? now()->addMonths(rand(1, 11)) : now()->addDays(rand(1, 25));

        return $spend;
    }

    private function grantOneTimePurchases(User $user): int
    {
        $skus = array_keys(self::ONE_TIME_SKUS);
        $purchaseCount = rand(1, 3);
        $spend = 0;

        for ($p = 0; $p < $purchaseCount; $p++) {
            $sku = $skus[array_rand($skus)];
            $at = $this->randomPastDate();
            $this->recordPurchase($user, $sku, self::ONE_TIME_SKUS[$sku], $at);
            $spend += self::ONE_TIME_SKUS[$sku];
        }

        return $spend;
    }
}
