<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Real monetization dashboard — every figure here is derived from completed `Purchase` rows (see
 * StoreController/VipController/FounderPackController, which all now write one on every real-money
 * event) plus character activity, not a projection or a mock. No refund or cost-of-goods data exists
 * anywhere in this app, so — unlike the original design mock — there's no "margin"/"refund rate" tile;
 * everything shown here is something this app can actually prove. */
class GmRevenueController extends Controller
{
    /** Display-only catalog for the price ladder panel — mirrors the real prices declared in
     * StoreController::GEM_PACKS/OTHER_SKUS, VipController::TIERS/YEARLY_PRICE_CENTS/LIFETIME_PRICE_CENTS,
     * and FounderPackController::PRICE_CENTS. `match` lists every sku this product's buyer/revenue
     * figures should be summed across (a VIP tier is sold monthly, yearly, and via renewal invoices). */
    private const PRODUCTS = [
        ['key' => 'gems_1000', 'match' => ['gems_1000'], 'label' => '1,000 Gems', 'kind' => 'CURRENCY', 'price_cents' => 499],
        ['key' => 'gems_2000', 'match' => ['gems_2000'], 'label' => '2,000 Gems (+10%)', 'kind' => 'CURRENCY', 'price_cents' => 999],
        ['key' => 'gems_4000', 'match' => ['gems_4000'], 'label' => '4,000 Gems (+10%)', 'kind' => 'CURRENCY', 'price_cents' => 1999],
        ['key' => 'gems_14000', 'match' => ['gems_14000'], 'label' => '14,000 Gems (+10%)', 'kind' => 'CURRENCY', 'price_cents' => 6999],
        ['key' => 'pass_ashfall', 'match' => ['pass_ashfall'], 'label' => 'Ashfall Season Pass', 'kind' => 'BATTLE PASS', 'price_cents' => 499],
        ['key' => 'remove_ads', 'match' => ['remove_ads'], 'label' => 'Remove Ads', 'kind' => 'CONVENIENCE', 'price_cents' => 499],
        ['key' => 'auto_battle_60', 'match' => ['auto_battle_60'], 'label' => '1 Hour Auto-Attack', 'kind' => 'CONVENIENCE', 'price_cents' => 99],
        ['key' => 'vip_bronze', 'match' => ['vip_bronze_month', 'vip_bronze_year', 'vip_bronze_renewal'], 'label' => 'Bronze Rank', 'kind' => 'RANK', 'price_cents' => 299],
        ['key' => 'vip_gold', 'match' => ['vip_gold_month', 'vip_gold_year', 'vip_gold_renewal'], 'label' => 'Gold Rank', 'kind' => 'RANK', 'price_cents' => 499],
        ['key' => 'vip_diamond', 'match' => ['vip_diamond_month', 'vip_diamond_year', 'vip_diamond_renewal'], 'label' => 'Diamond Rank', 'kind' => 'RANK', 'price_cents' => 999],
        ['key' => 'vip_lifetime', 'match' => ['vip_lifetime'], 'label' => 'Lifetime Rank', 'kind' => 'RANK', 'price_cents' => 19999],
        ['key' => 'founder_pack', 'match' => ['founder_pack'], 'label' => 'Founder Beta Pack', 'kind' => 'ONE-TIME', 'price_cents' => 1499],
    ];

    private const STREAMS = [
        'gems' => ['label' => 'Gem Bundles', 'color' => '#eab308'],
        'battle_pass' => ['label' => 'Battle Pass', 'color' => '#e8482f'],
        'vip' => ['label' => 'Rank Subscriptions', 'color' => '#60a5fa'],
        'vip_lifetime' => ['label' => 'Lifetime Rank', 'color' => '#4ade80'],
        'founder' => ['label' => 'Founder Beta Pack', 'color' => '#a855f7'],
        'remove_ads' => ['label' => 'Remove Ads', 'color' => '#7c8794'],
        'auto_battle' => ['label' => 'Auto-Battle Tokens', 'color' => '#f472b6'],
    ];

    public function index(Request $request)
    {
        $range = $request->query('range', '30d');
        $sort = $request->query('sort', 'rev');

        $end = now();
        $start = $this->rangeStart($range);
        $spanDays = max(1, (int) ceil($start->diffInHours($end) / 24));
        $priorEnd = (clone $start)->subSecond();
        $priorStart = (clone $start)->subDays($spanDays);

        $completedBetween = fn (Carbon $from, Carbon $to) => Purchase::where('status', 'completed')
            ->whereBetween('created_at', [$from, $to]);

        $rowsThis = $completedBetween($start, $end)->get(['user_id', 'sku', 'amount_cents', 'created_at']);
        $rowsPrior = $completedBetween($priorStart, $priorEnd)->get(['sku', 'amount_cents']);

        $streamTotals = collect(self::STREAMS)->mapWithKeys(fn ($m, $k) => [$k => ['revenue' => 0, 'buyers' => [], 'transactions' => 0]])->all();
        foreach ($rowsThis as $row) {
            $key = $this->streamKeyFor($row->sku);
            if (! isset($streamTotals[$key])) {
                continue;
            }
            $streamTotals[$key]['revenue'] += $row->amount_cents;
            $streamTotals[$key]['buyers'][$row->user_id] = true;
            $streamTotals[$key]['transactions']++;
        }

        $priorTotals = collect(self::STREAMS)->mapWithKeys(fn ($m, $k) => [$k => 0])->all();
        foreach ($rowsPrior as $row) {
            $key = $this->streamKeyFor($row->sku);
            if (isset($priorTotals[$key])) {
                $priorTotals[$key] += $row->amount_cents;
            }
        }

        $netRevenueCents = $rowsThis->sum('amount_cents');
        $payingUsers = $rowsThis->pluck('user_id')->unique()->count();
        $transactions = $rowsThis->count();

        $streams = collect(self::STREAMS)->map(function ($meta, $key) use ($streamTotals, $priorTotals) {
            $revenue = $streamTotals[$key]['revenue'];
            $prior = $priorTotals[$key];
            $trendPct = $prior > 0 ? (int) round((($revenue - $prior) / $prior) * 100) : ($revenue > 0 ? 100 : 0);

            return [
                'key' => $key,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'revenue_cents' => $revenue,
                'buyers' => count($streamTotals[$key]['buyers']),
                'transactions' => $streamTotals[$key]['transactions'],
                'trend_pct' => $trendPct,
            ];
        });

        $sortedStreams = $streams->sort(fn ($a, $b) => match ($sort) {
            'buyers' => $b['buyers'] <=> $a['buyers'],
            'trend' => $b['trend_pct'] <=> $a['trend_pct'],
            default => $b['revenue_cents'] <=> $a['revenue_cents'],
        })->values();

        $dailyDays = min($spanDays, 90);
        $dailyStart = (clone $end)->subDays($dailyDays - 1)->startOfDay();
        $dailyRows = Purchase::where('status', 'completed')->where('created_at', '>=', $dailyStart)->get(['sku', 'amount_cents', 'created_at']);

        $byDay = [];
        foreach ($dailyRows as $row) {
            $day = $row->created_at->toDateString();
            $key = $this->streamKeyFor($row->sku);
            $byDay[$day][$key] = ($byDay[$day][$key] ?? 0) + $row->amount_cents;
        }

        $dau = Character::where('last_active_at', '>=', $dailyStart)
            ->selectRaw('DATE(last_active_at) as day, count(distinct id) as count')
            ->groupBy('day')
            ->pluck('count', 'day');

        $daily = [];
        $arpdau = [];
        for ($i = $dailyDays - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $entry = ['date' => $day];
            foreach (self::STREAMS as $key => $meta) {
                $entry[$key] = round(($byDay[$day][$key] ?? 0) / 100, 2);
            }
            $daily[] = $entry;

            $activeToday = (int) ($dau[$day] ?? 0);
            $revenueToday = array_sum($byDay[$day] ?? []);
            $arpdau[] = [
                'date' => $day,
                'value' => $activeToday > 0 ? round(($revenueToday / 100) / $activeToday, 3) : 0,
            ];
        }

        $activePlayers = Character::where('last_active_at', '>=', $start)->count();
        $checkoutStarters = Purchase::where('created_at', '>=', $start)->distinct('user_id')->count('user_id');

        $mixBase = $sortedStreams->filter(fn ($s) => $s['revenue_cents'] > 0);
        $mix = $mixBase->map(fn ($s) => [
            'label' => $s['label'],
            'color' => $s['color'],
            'pct' => $netRevenueCents > 0 ? round($s['revenue_cents'] / $netRevenueCents * 100) : 0,
        ])->values();

        $alerts = $streams
            ->filter(fn ($s) => abs($s['trend_pct']) >= 20 && ($s['revenue_cents'] > 0 || $priorTotals[$s['key']] > 0))
            ->sortByDesc(fn ($s) => abs($s['trend_pct']))
            ->take(3)
            ->map(fn ($s) => [
                'text' => $s['label'].' is '.($s['trend_pct'] >= 0 ? 'up ' : 'down ').abs($s['trend_pct']).'% vs the prior period.',
                'meta' => $this->fmt($s['revenue_cents']).' this period',
                'dot' => $s['trend_pct'] >= 0 ? '#4ade80' : '#e8482f',
            ])->values();

        return response()->json([
            'range' => $range,
            'sort' => $sort,
            'window' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $spanDays],
            'kpis' => [
                'net_revenue_cents' => $netRevenueCents,
                'paying_users' => $payingUsers,
                'arppu_cents' => $payingUsers > 0 ? (int) round($netRevenueCents / $payingUsers) : 0,
                'transactions' => $transactions,
                'avg_transaction_cents' => $transactions > 0 ? (int) round($netRevenueCents / $transactions) : 0,
            ],
            'streams' => $sortedStreams,
            'daily' => $daily,
            'arpdau' => $arpdau,
            'funnel' => [
                ['label' => 'Active players', 'value' => $activePlayers],
                ['label' => 'Started checkout', 'value' => $checkoutStarters],
                ['label' => 'Completed purchase', 'value' => $payingUsers],
            ],
            'mix' => $mix,
            'products' => $this->productCatalog(),
            'alerts' => $alerts,
        ]);
    }

    private function rangeStart(string $range): Carbon
    {
        return match ($range) {
            '7d' => now()->subDays(6)->startOfDay(),
            'season' => now()->startOfMonth(),
            'ltd' => ($earliest = Purchase::min('created_at')) ? Carbon::parse($earliest)->startOfDay() : now()->startOfDay(),
            default => now()->subDays(29)->startOfDay(),
        };
    }

    private function streamKeyFor(string $sku): string
    {
        return match (true) {
            str_starts_with($sku, 'gems_') => 'gems',
            $sku === 'pass_ashfall' => 'battle_pass',
            $sku === 'vip_lifetime' => 'vip_lifetime',
            str_starts_with($sku, 'vip_') => 'vip',
            $sku === 'founder_pack' => 'founder',
            $sku === 'remove_ads' => 'remove_ads',
            $sku === 'auto_battle_60' => 'auto_battle',
            default => 'other',
        };
    }

    private function fmt(int $cents): string
    {
        return '€'.number_format($cents / 100, 2);
    }

    /** All-time buyer/revenue figures per catalog product, plus a dynamically-assigned "MOST SOLD"/
     * "TOP EARNER" tag — no hardcoded tag copy, since which product actually wins changes as real
     * purchase data accumulates. */
    private function productCatalog(): array
    {
        $rows = Purchase::where('status', 'completed')->get(['user_id', 'sku', 'amount_cents']);

        $stats = [];
        foreach (self::PRODUCTS as $p) {
            $matching = $rows->whereIn('sku', $p['match']);
            $stats[$p['key']] = [
                'buyers' => $matching->pluck('user_id')->unique()->count(),
                'revenue_cents' => $matching->sum('amount_cents'),
            ];
        }

        $mostSoldKey = collect($stats)->sortByDesc('buyers')->keys()->first();
        $topEarnerKey = collect($stats)->sortByDesc('revenue_cents')->keys()->first();

        return collect(self::PRODUCTS)->map(function ($p) use ($stats, $mostSoldKey, $topEarnerKey) {
            $s = $stats[$p['key']];
            $tag = match (true) {
                $s['revenue_cents'] <= 0 => null,
                $p['key'] === $topEarnerKey => 'TOP EARNER',
                $p['key'] === $mostSoldKey => 'MOST SOLD',
                default => null,
            };

            return [
                'key' => $p['key'],
                'label' => $p['label'],
                'kind' => $p['kind'],
                'price_cents' => $p['price_cents'],
                'buyers' => $s['buyers'],
                'revenue_cents' => $s['revenue_cents'],
                'tag' => $tag,
            ];
        })->values()->all();
    }
}
