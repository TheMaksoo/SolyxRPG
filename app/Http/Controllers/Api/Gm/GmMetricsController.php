<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Http\Request;

class GmMetricsController extends Controller
{
    /** Mirrors VipController::TIERS' price_cents so the estimated MRR uses the real subscription prices. */
    private const VIP_TIER_PRICE_CENTS = [
        'bronze' => 299,
        'gold' => 499,
        'diamond' => 999,
    ];

    /**
     * Real, exact "this month" revenue metrics derived from completed Purchase rows (one-time SKUs)
     * plus an estimate of recurring VIP revenue based on currently-active subscribers (no per-charge
     * VIP ledger exists to compute an exact figure).
     */
    public function index(Request $request)
    {
        $month = now()->month;
        $year = now()->year;

        $completedThisMonth = fn () => Purchase::where('status', 'completed')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $gemPackRevenueCents = (clone $completedThisMonth())->where('sku', 'like', 'gems_%')->sum('amount_cents');
        $seasonPassRevenueCents = (clone $completedThisMonth())->where('sku', 'pass_ashfall')->sum('amount_cents');
        $otherRevenueCents = (clone $completedThisMonth())->whereIn('sku', ['remove_ads', 'auto_battle_60'])->sum('amount_cents');

        $gemPacksSoldCount = (clone $completedThisMonth())->where('sku', 'like', 'gems_%')->count();
        $seasonPassesSoldCount = (clone $completedThisMonth())->where('sku', 'pass_ashfall')->count();

        $activeVipCounts = User::where('vip_tier', '!=', 'none')
            ->where('vip_expires_at', '>', now())
            ->select('vip_tier')
            ->get()
            ->countBy('vip_tier');

        $vipMrrCents = 0;
        foreach (self::VIP_TIER_PRICE_CENTS as $tier => $priceCents) {
            $vipMrrCents += ($activeVipCounts[$tier] ?? 0) * $priceCents;
        }

        return response()->json([
            'period' => ['month' => $month, 'year' => $year, 'label' => now()->format('F Y')],
            'gem_pack_revenue_cents' => $gemPackRevenueCents,
            'season_pass_revenue_cents' => $seasonPassRevenueCents,
            'other_revenue_cents' => $otherRevenueCents,
            'total_one_time_revenue_cents' => $gemPackRevenueCents + $seasonPassRevenueCents + $otherRevenueCents,
            'gem_packs_sold_count' => $gemPacksSoldCount,
            'season_passes_sold_count' => $seasonPassesSoldCount,
            'active_vip_counts' => [
                'bronze' => $activeVipCounts['bronze'] ?? 0,
                'gold' => $activeVipCounts['gold'] ?? 0,
                'diamond' => $activeVipCounts['diamond'] ?? 0,
            ],
            // Estimate, not an exact figure — there's no per-charge VIP ledger, this is
            // (active subscribers per tier) * (current tier price), so lapsed/mid-cycle changes aren't reflected.
            'vip_mrr_cents' => $vipMrrCents,
        ]);
    }
}
