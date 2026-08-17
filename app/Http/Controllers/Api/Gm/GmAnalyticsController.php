<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\BattlePass;
use App\Models\Character;
use App\Models\CharacterQuest;
use App\Models\ClassProgression;
use App\Models\CraftingJob;
use App\Models\Dungeon;
use App\Models\DungeonRun;
use App\Models\ErrorLog;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Item;
use App\Models\Monster;
use App\Models\Purchase;
use App\Models\PvpLiveMatch;
use App\Models\PvpRecord;
use App\Models\Recipe;
use App\Models\Skill;
use App\Models\SupportTicket;
use App\Models\TradeSkillLog;
use App\Models\User;
use App\Models\Zone;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Real activity/engagement metrics for the GM Overview → Activity tab — a Cloudflare-analytics-style
 * read: big headline numbers, a 30-day daily trend per series, and a "what are people actually doing"
 * breakdown across every content system, so a GM can see engagement shape at a glance rather than
 * digging through raw tables. Every series is a real query against gameplay tables, nothing simulated.
 */
class GmAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $days = max(7, min(90, (int) $request->query('days', 30)));
        $since = now()->subDays($days)->startOfDay();

        return response()->json([
            'range_days' => $days,
            'headline' => $this->headline(),
            'daily' => [
                'signups' => $this->dailyCounts(User::query(), 'created_at', $since, $days),
                'battles' => $this->dailyCounts(Battle::query(), 'created_at', $since, $days),
                'active_characters' => $this->dailyActiveCharacters($since, $days),
                'referrals' => $this->dailyCounts(User::whereNotNull('referred_by_user_id'), 'created_at', $since, $days),
            ],
            'referral_funnel' => $this->referralFunnel(),
            'battle_pass_premium' => $this->battlePassPremiumStats(),
            'level_growth' => $this->levelGrowth(),
            'kills_to_level_up' => $this->killsToLevelUp(),
            'unlock_timeline' => $this->unlockTimeline(),
            'content_interest' => $this->contentInterest($since),
            'class_distribution' => Character::select('base_class')
                ->selectRaw('count(*) as count')
                ->groupBy('base_class')
                ->pluck('count', 'base_class'),
            'level_distribution' => $this->levelDistribution(),
            'pvp_tier_distribution' => $this->pvpTierDistribution(),
            'vip_tier_distribution' => $this->vipTierDistribution(),
            'retention' => $this->retention(),
            'top_players' => $this->topPlayers(),
            'active_players' => $this->activePlayers(),
            'live_health' => [
                'errors_24h' => ErrorLog::where('created_at', '>=', now()->subDay())->count(),
                'errors_7d' => ErrorLog::where('created_at', '>=', now()->subDays(7))->count(),
                'open_tickets' => SupportTicket::whereIn('status', ['open', 'pending'])->count(),
            ],
        ]);
    }

    /** Item balancing data for the GM console's Balancing tab — previously there was no aggregate view
     * of items at all (only the raw per-item CRUD editor), so a mispriced or over/under-stated item
     * (like the legendary-gold-price dip and dominated common-tier tools fixed earlier this pass) had to
     * be spotted by manually reading the seeder rather than seeing it in a chart. Static content data
     * (not player activity), so it's a separate endpoint from index() rather than another `days`-windowed
     * series. */
    public function itemBalance(Request $request)
    {
        // Was a fixed column list including 'stat_json' — that column no longer exists (see Item's
        // STAT_KEYS/getStatJsonAttribute()), and the accessor needs every real stat column loaded on the
        // model to assemble it, so this now just fetches every column rather than hand-listing them.
        $items = Item::all();

        $statUsage = [];
        $rows = [];
        foreach ($items as $item) {
            $statTotal = 0;
            foreach ((array) $item->stat_json as $statKey => $value) {
                if (! is_numeric($value)) {
                    continue; // skips flavor-only keys like arm_path_name, duration_seconds, etc.
                }
                $statUsage[$statKey] = ($statUsage[$statKey] ?? 0) + 1;
                $statTotal += abs((float) $value);
            }

            $rows[] = [
                'key' => $item->key,
                'name' => $item->name,
                'type' => $item->type,
                'rarity' => $item->rarity,
                'min_level' => $item->min_level,
                'price_gold' => $item->price_gold,
                'price_gems' => $item->price_gems,
                // A rough, unweighted sum of every numeric stat_json value — stat_json deliberately mixes
                // flat stats (atk/def) with percentages (crit/dodge_pct) and situational bonuses
                // (gather_yield_bonus), so this is a coarse "how loaded is this item" signal for spotting
                // outliers at a glance, not a precise power score.
                'stat_total' => round($statTotal, 1),
            ];
        }

        $rarityOrder = ['common', 'rare', 'epic', 'legendary', 'mythic'];

        return response()->json([
            'total_items' => $items->count(),
            'rarity_distribution' => $items->countBy('rarity')->sortKeysUsing(fn ($a, $b) => array_search($a, $rarityOrder) <=> array_search($b, $rarityOrder)),
            'price_currency_split' => [
                'gold_only' => $items->filter(fn ($i) => $i->price_gold !== null && $i->price_gems === null)->count(),
                'gems_only' => $items->filter(fn ($i) => $i->price_gems !== null && $i->price_gold === null)->count(),
                'both' => $items->filter(fn ($i) => $i->price_gold !== null && $i->price_gems !== null)->count(),
                'neither' => $items->filter(fn ($i) => $i->price_gold === null && $i->price_gems === null)->count(),
            ],
            'price_distribution' => [
                'gold' => $this->bucketPrices($items->pluck('price_gold')->filter(fn ($p) => $p !== null)),
                // Bucket edges are gem counts, not EUR — the Gem Store's flat ~€0.005/gem rate (see
                // StoreController::GEM_PACKS) means 2000 gems is the €10 real-money ceiling every item is
                // meant to respect; the top bucket is exactly the overage this was built to catch.
                'gems' => $this->bucketPrices($items->pluck('price_gems')->filter(fn ($p) => $p !== null), [100, 500, 1000, 2000]),
            ],
            'stat_usage' => collect($statUsage)->sortDesc(),
            'items' => $rows,
        ]);
    }

    /** Buckets a list of prices into named ranges for a histogram — default edges suit gold's much wider
     * spread, pass gem-scale edges (see itemBalance()) for the gems side. $edges are the upper bound of
     * every bucket except the last, which is "edge N and up". */
    private function bucketPrices($prices, array $edges = [100, 1000, 10000]): array
    {
        $labels = [];
        $prev = 0;
        foreach ($edges as $edge) {
            $labels[] = "{$prev}-{$edge}";
            $prev = $edge;
        }
        $labels[] = "{$prev}+";

        $counts = array_fill_keys($labels, 0);
        foreach ($prices as $price) {
            foreach ($edges as $i => $edge) {
                if ($price <= $edge) {
                    $counts[$labels[$i]]++;
                    continue 2;
                }
            }
            $counts[end($labels)]++;
        }

        return $counts;
    }

    /** Referral breakdown for the doughnut chart (pending vs. qualified referees) plus the two
     * "how many actually finished" totals: referrer milestone rewards paid out, and referee level-5
     * bonuses paid out — see ReferralService for what "qualified"/a milestone/a bonus mean. */
    private function referralFunnel(): array
    {
        $total = User::whereNotNull('referred_by_user_id')->count();
        $qualified = User::whereNotNull('referred_by_user_id')
            ->whereHas('characters', fn ($q) => $q->where('level', '>=', ReferralService::REQUIRED_LEVEL))
            ->count();

        return [
            'pending' => $total - $qualified,
            'qualified' => $qualified,
            'reward_milestones_granted' => (int) User::sum('referral_rewards_claimed'),
            'referee_bonuses_granted' => User::whereNotNull('referral_bonus_granted_at')->count(),
        ];
    }

    /** Real seconds one simulated fight "costs" for the assumed-hours estimate below — same constant
     * AutoBattleService uses for its own real-world pacing assumption. */
    private const ASSUMED_SECONDS_PER_FIGHT = 20;

    /** Cumulative XP required to reach each level, sampled every 10 levels through 250 — the class-tier
     * ladder, Skill Mastery, and Reforging now extend real content out to level 200+ (see
     * unlockTimeline() below), so this samples the same range rather than stopping at the old level-150
     * ceiling. Paired with two time-to-reach estimates: an "assumed" figure from the same cumulative-kill
     * model killsToLevelUp() uses, and the REAL average playtime_seconds of characters currently sitting
     * at each sampled level — the latter is necessarily noisy for high levels reached before playtime
     * tracking existed (added 2026-08-04, see the 'tracked_since' field below), so sample_size is
     * included for every point rather than presenting it as more reliable than it is. */
    private function levelGrowth(): array
    {
        $sampleEvery = 10;
        $topLevel = 250;
        $trackingStartedAt = '2026-08-04';

        $monsters = Monster::where('enabled', true)->where('is_boss', false)->orderBy('min_level')->get(['min_level', 'xp']);

        $sampleLevels = [];
        for ($level = 1; $level <= $topLevel; $level++) {
            if ($level === 1 || $level % $sampleEvery === 0) {
                $sampleLevels[] = $level;
            }
        }

        // One query for every sample's cohort instead of one query per sample (previously ~26 round
        // trips) — conditional aggregation computes each bucket's count/avg in the same pass, preserving
        // the original per-bucket ranges exactly (including the intentional overlap at each bucket's
        // edges, e.g. level 10 counts toward both the level-1 and level-10 buckets).
        // Plain query builder, not Eloquent — Character::query()->first() would return a Model whose
        // (array) cast exposes internal object properties, not the selected columns.
        $cohortQuery = DB::table('characters');
        foreach ($sampleLevels as $level) {
            $bucketTop = $level + $sampleEvery - 1;
            $cohortQuery->selectRaw("COUNT(CASE WHEN level BETWEEN ? AND ? THEN 1 END) AS cnt_{$level}", [$level, $bucketTop]);
            $cohortQuery->selectRaw("AVG(CASE WHEN level BETWEEN ? AND ? THEN playtime_seconds END) AS avg_{$level}", [$level, $bucketTop]);
        }
        $cohorts = (array) $cohortQuery->first();

        $cumulative = 0;
        $cumulativeKills = 0;
        $labels = [];
        $data = [];
        $assumedHours = [];
        $realAvgHours = [];
        $sampleSizes = [];

        for ($level = 1; $level <= $topLevel; $level++) {
            if ($level > 1) {
                $cumulative += Character::xpForLevel($level - 1);
            }

            $reachable = $monsters->filter(fn (Monster $m) => $m->min_level <= $level && $m->xp > 0);
            if ($reachable->isNotEmpty()) {
                $avgXp = $reachable->avg('xp');
                $cumulativeKills += ceil(Character::xpForLevel($level) / $avgXp);
            }

            if ($level === 1 || $level % $sampleEvery === 0) {
                $labels[] = (string) $level;
                $data[] = $cumulative;
                $assumedHours[] = round($cumulativeKills * self::ASSUMED_SECONDS_PER_FIGHT / 3600, 1);

                // "Currently sitting at this level" (this sample up to the next one) as a proxy cohort for
                // "took roughly this long to get here" — not a historical snapshot, since we don't record
                // playtime-at-level-X, only a character's current total.
                $count = (int) $cohorts["cnt_{$level}"];
                $avgSeconds = $cohorts["avg_{$level}"];
                $sampleSizes[] = $count;
                $realAvgHours[] = $count ? round($avgSeconds / 3600, 1) : null;
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'assumed_hours' => $assumedHours,
            'real_avg_hours' => $realAvgHours,
            'sample_sizes' => $sampleSizes,
            'playtime_tracked_since' => $trackingStartedAt,
        ];
    }

    /** Every 10 levels, the TOTAL kills from level 1 to get there — not just the last level's step —
     * averaged across every non-boss monster reachable at each level along the way (bosses are
     * dungeon-only, never wandered into, see BattleController::walk()). Matches the real grind far
     * better than a single-level snapshot: this cumulative, averaged model lines up closely with
     * observed play (~1000 kills to hit level 10, ~1800 to hit level 15). A rough grind-pace gut check
     * for a GM, not a promise (real XP varies by rolled Grade). */
    private function killsToLevelUp(): array
    {
        $monsters = Monster::where('enabled', true)->where('is_boss', false)->orderBy('min_level')->get(['name', 'min_level', 'xp']);

        $rows = [];
        $cumulativeKills = 0;
        for ($level = 1; $level < 250; $level++) {
            $reachable = $monsters->filter(fn (Monster $m) => $m->min_level <= $level && $m->xp > 0);
            if ($reachable->isEmpty()) {
                continue;
            }

            $avgXp = $reachable->avg('xp');
            $cumulativeKills += ceil(Character::xpForLevel($level) / $avgXp);

            if (($level + 1) % 10 === 0) {
                $rows[] = [
                    'level' => $level + 1,
                    'monster_name' => $reachable->count() === 1
                        ? $reachable->first()->name
                        : "{$reachable->count()} monsters (avg)",
                    'monster_xp' => (int) round($avgXp),
                    'kills' => $cumulativeKills,
                ];
            }
        }

        return $rows;
    }

    /** Every level-gated zone/dungeon, sorted ascending — paired with the cumulative XP needed to reach
     * that level so a GM can see exactly how much grinding stands between a player and the next unlock.
     * Nav feature unlocks (Shop, World Map, etc.) are merged in on the frontend from navigation.js rather
     * than duplicated here, since that list is already the single source of truth for the sidebar. */
    /** Every level-gated thing in the game, in one merged timeline — zones/dungeons (real unlock gates),
     * monsters/skills/recipes (level_req/min_level — when a fight or a craft option first becomes
     * reachable). Nav feature unlocks (Shop, World Map, ...) are merged in on the frontend from
     * navigation.js, since that file is already the single source of truth for the sidebar. */
    private function unlockTimeline(): array
    {
        $zones = Zone::where('enabled', true)->get()->map(fn (Zone $z) => [
            'level' => $z->min_level,
            'name' => $z->name,
            'type' => 'zone',
        ]);

        $dungeons = Dungeon::where('enabled', true)->get()->map(fn (Dungeon $d) => [
            'level' => $d->min_level,
            'name' => $d->name,
            'type' => 'dungeon',
        ]);

        $monsters = Monster::where('enabled', true)->get()->map(fn (Monster $m) => [
            'level' => $m->min_level,
            'name' => $m->name.($m->is_boss ? ' (boss)' : ''),
            'type' => 'monster',
        ]);

        // Rank-ups beyond the first (rank_levels[0] === level_req, already the base entry below) are
        // real additional unlock moments — a chosen branch keeps granting stronger ranks well past its
        // own level_req (see SkillSeeder's +8/+10/+15-per-rank offsets), but were previously invisible
        // here, making this chart overstate how empty the stretch between zone/dungeon/class-tier
        // milestones actually feels for a player who's committed to a branch.
        $skills = Skill::all()->flatMap(function (Skill $s) {
            $entries = collect([[
                'level' => $s->level_req,
                'name' => "{$s->name} ({$s->class_scope})",
                'type' => 'skill',
            ]]);

            foreach (array_slice($s->rank_levels ?? [], 1) as $i => $rankLevel) {
                $entries->push([
                    'level' => $rankLevel,
                    'name' => "{$s->name} rank ".($i + 2)." ({$s->class_scope})",
                    'type' => 'skill',
                ]);
            }

            return $entries;
        });

        $recipes = Recipe::where('enabled', true)->get()->map(fn (Recipe $r) => [
            'level' => $r->min_level,
            'name' => $r->name,
            'type' => 'recipe',
        ]);

        // Taming isn't a static level_req column anywhere — CombatService::attemptTame() gates it
        // per-zone at zone.min_level + 10, so it's derived here rather than read off a model field.
        $taming = Zone::where('enabled', true)->get()->map(fn (Zone $z) => [
            'level' => $z->min_level + 10,
            'name' => "Taming: {$z->name}",
            'type' => 'taming',
        ]);

        // One entry per class-ladder tier (t20/t50/t100/t150/t200) rather than all 40 ClassProgression
        // rows (8 per level_cap, since each tier has 2 branches × 4 classes) — the level is the real
        // unlock moment; which specific branch a player picks doesn't need its own timeline point.
        $tierNames = [20 => 'Specialization', 50 => 'Profession', 100 => 'Ascension', 150 => 'Apex', 200 => 'Transcendence'];
        $classTiers = ClassProgression::select('level_cap')->distinct()->orderBy('level_cap')->pluck('level_cap')
            ->map(fn (int $level) => [
                'level' => $level,
                'name' => 'Class Path: '.($tierNames[$level] ?? "Tier {$level}"),
                'type' => 'class_tier',
            ]);

        // Skill Mastery and Reforging aren't backed by any single model row — both are pure level gates
        // plus GM-tunable config (see CharacterController::effectiveSkillMaxLevel, ReforgeService), so
        // they're synthesized here the same way taming is above.
        $mastery = collect([[
            'level' => (int) GameConfig::number('skill_mastery_unlock_level', 150),
            'name' => 'Skill Mastery (bonus skill ranks)',
            'type' => 'mastery',
        ]]);
        $reforging = collect([[
            'level' => (int) GameConfig::number('reforge_unlock_level', 200),
            'name' => 'Reforging (gear enchanting)',
            'type' => 'reforging',
        ]]);

        return $zones->concat($dungeons)->concat($monsters)->concat($skills)->concat($recipes)->concat($taming)
            ->concat($classTiers)->concat($mastery)->concat($reforging)
            ->sortBy('level')->values()->map(fn ($row) => [
                ...$row,
                'cumulative_xp' => $this->cumulativeXpForLevel($row['level']),
            ])->all();
    }

    private function cumulativeXpForLevel(int $level): int
    {
        $cumulative = 0;
        for ($i = 1; $i < $level; $i++) {
            $cumulative += Character::xpForLevel($i);
        }

        return $cumulative;
    }

    private function headline(): array
    {
        $active24h = Character::where('updated_at', '>=', now()->subDay())->count();
        $active30d = Character::where('updated_at', '>=', now()->subDays(30))->count();
        
        $totalUsers = User::count();
        $totalCharacters = Character::count();
        $usersWithoutCharacters = User::doesntHave('characters')->count();
        $usersWithMultipleCharacters = User::has('characters', '>=', 2)->count();

        // Battle pass statistics
        $battlePassStats = $this->battlePassStats();

        return [
            'total_users' => $totalUsers,
            'total_characters' => $totalCharacters,
            'users_without_characters' => $usersWithoutCharacters,
            'users_with_multiple_characters' => $usersWithMultipleCharacters,
            'new_users_today' => User::whereDate('created_at', now()->toDateString())->count(),
            'new_users_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'active_1h' => Character::where('updated_at', '>=', now()->subHour())->count(),
            'active_24h' => $active24h,
            'active_7d' => Character::where('updated_at', '>=', now()->subDays(7))->count(),
            'active_30d' => $active30d,
            'dau_mau_pct' => $active30d > 0 ? round($active24h / $active30d * 100) : null,
            'battles_today' => Battle::whereDate('created_at', now()->toDateString())->count(),
            'battles_total' => Battle::count(),
            // "Used" = actually copied their link/code (see ReferralController::trackCopy) — a top-of-
            // funnel engagement signal, distinct from referrals_signed_up (an actual conversion). The
            // signup/qualified breakdown lives in referralFunnel() below.
            'referrals_used' => (int) User::sum('referral_link_copies'),
            'referrals_signed_up' => User::whereNotNull('referred_by_user_id')->count(),
            // Battle pass engagement metrics
            'battle_pass' => $battlePassStats,
        ];
    }

    /** Real Day-1/7/30 retention: of users who signed up long enough ago to have had the chance,
     * what % had a character still active N days after signup. Cohort window is capped at 60 days back
     * so "Day 30" always has a real observation window instead of comparing against yesterday's signups. */
    private function retention(): array
    {
        $cohortStart = now()->subDays(60);

        return array_map(function (int $n) use ($cohortStart) {
            $cohortEnd = now()->subDays($n);
            $cohortSize = User::whereBetween('created_at', [$cohortStart, $cohortEnd])->count();

            $retained = $cohortSize > 0
                ? User::whereBetween('created_at', [$cohortStart, $cohortEnd])
                    ->whereExists(function ($q) use ($n) {
                        $q->select('id')
                            ->from('characters')
                            ->whereColumn('characters.user_id', 'users.id')
                            ->whereRaw('characters.updated_at >= DATE_ADD(users.created_at, INTERVAL ? DAY)', [$n]);
                    })
                    ->count()
                : 0;

            return [
                'window' => $n,
                'label' => "Day {$n}",
                'pct' => $cohortSize > 0 ? round($retained / $cohortSize * 100) : null,
                'cohort_size' => $cohortSize,
            ];
        }, [1, 7, 30]);
    }

    /** Top 5 characters by effective power — the same formula the Leaderboard page uses, just capped to
     * a small "who's actually strong right now" glance for the GM instead of the full ranked board. */
    private function topPlayers()
    {
        return Character::with(['attributes_', 'inventory.item', 'pets.pet'])
            ->get()
            ->map(fn (Character $c) => [
                'name' => $c->name,
                'base_class' => $c->base_class,
                'level' => $c->level,
                'power' => $c->effectiveStats()['power'],
            ])
            ->sortByDesc('power')
            ->take(5)
            ->values();
    }

    /** The 100 most-recently-active characters right now — a live "who's actually online" glance,
     * distinct from topPlayers() (which ranks by power, not recency). Capped at 100 so this never turns
     * into an unbounded table as the player base grows. */
    private function activePlayers()
    {
        return Character::with('user')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (Character $c) => [
                'name' => $c->name,
                'user_name' => $c->user?->name,
                'base_class' => $c->base_class,
                'level' => $c->level,
                'last_active_at' => $c->updated_at,
                'last_action' => $c->last_action ?? 'Idle',
            ]);
    }

    /** One row per day for the last $days days (zero-filled — a day with no rows still appears as 0,
     * so the chart's x-axis is a continuous timeline rather than skipping quiet days). */
    private function dailyCounts($query, string $column, $since, int $days): array
    {
        $rows = $query->where($column, '>=', $since)
            ->selectRaw("DATE({$column}) as day, count(*) as count")
            ->groupBy('day')
            ->pluck('count', 'day');

        return $this->zeroFill($rows, $days);
    }

    private function dailyActiveCharacters($since, int $days): array
    {
        $rows = Character::where('updated_at', '>=', $since)
            ->selectRaw('DATE(updated_at) as day, count(distinct id) as count')
            ->groupBy('day')
            ->pluck('count', 'day');

        return $this->zeroFill($rows, $days);
    }

    private function zeroFill($rows, int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $out[] = ['date' => $day, 'count' => (int) ($rows[$day] ?? 0)];
        }

        return $out;
    }

    /** "What are people actually doing" — a real count of the last 7 days' worth of activity per
     * content system, so a GM can see which systems get played versus ignored. */
    private function contentInterest($since): array
    {
        // Battles deliberately excluded — its count so dwarfs every other system's that the bars for
        // everything else become invisible next to it; battles already get their own headline card and
        // trend line above, so nothing is lost by leaving it out of this specific comparison.
        return [
            ['key' => 'dungeons', 'label' => 'Dungeon runs', 'count' => DungeonRun::where('created_at', '>=', $since)->count()],
            ['key' => 'pvp', 'label' => 'PvP matches', 'count' => PvpLiveMatch::where('created_at', '>=', $since)->count()],
            ['key' => 'crafting', 'label' => 'Items crafted', 'count' => CraftingJob::where('created_at', '>=', $since)->count()],
            ['key' => 'gathering', 'label' => 'Gathering actions', 'count' => TradeSkillLog::where('created_at', '>=', $since)->count()],
            ['key' => 'quests', 'label' => 'Quests claimed', 'count' => CharacterQuest::where('claimed', true)->where('updated_at', '>=', $since)->count()],
        ];
    }

    /** Buckets characters by level band (1-9, 10-19, ...) so a GM can see where the playerbase actually
     * sits in the level curve, e.g. "half of everyone is stuck at 1-9" is a very different problem than
     * "most players are 40+". */
    private function levelDistribution()
    {
        return Character::selectRaw('FLOOR(level / 10) * 10 as bucket, count(*) as count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => ['bucket' => "{$row->bucket}-".($row->bucket + 9), 'count' => $row->count]);
    }

    /** How the ranked PvP population is spread across PvpRecord::PVP_TIERS — previously PvP had zero
     * GM-console visibility beyond a raw 7-day match count. Bucketed by rating threshold in SQL (the
     * thresholds come from a hardcoded const array, never user input) rather than fetching every row and
     * bucketing in PHP, so this stays cheap regardless of ranked population size. */
    private function pvpTierDistribution()
    {
        $tiers = PvpRecord::PVP_TIERS;
        $case = 'CASE ';
        for ($i = count($tiers) - 1; $i >= 0; $i--) {
            $case .= 'WHEN rating >= '.(int) $tiers[$i]['min_rating']." THEN '{$tiers[$i]['name']}' ";
        }
        $case .= "ELSE '{$tiers[0]['name']}' END as tier";

        return PvpRecord::query()
            ->selectRaw($case)
            ->selectRaw('count(*) as count')
            ->groupBy('tier')
            ->pluck('count', 'tier');
    }

    /** How the player base splits across VIP tiers right now (lifetime / active bronze-gold-diamond /
     * none-or-expired) — previously vip_tier was only editable per-player, never visualized in aggregate
     * despite driving dozens of GameConfig perk multipliers. Expired-but-not-renewed subscriptions count
     * as 'none' here, matching User::hasActiveVip()'s own definition of "active" exactly. */
    private function vipTierDistribution()
    {
        return User::query()
            ->selectRaw("
                CASE
                    WHEN vip_lifetime = 1 THEN 'lifetime'
                    WHEN vip_tier != 'none' AND vip_expires_at IS NOT NULL AND vip_expires_at > ? THEN vip_tier
                    ELSE 'none'
                END as bucket
            ", [now()])
            ->selectRaw('count(*) as count')
            ->groupBy('bucket')
            ->pluck('count', 'bucket');
    }

    /** Battle pass statistics for current season — shows how many players are engaged with the battle
     * pass system and how far along they are. Useful for tracking monetization and engagement with the
     * seasonal progression system. */
    private function battlePassStats(): array
    {
        $currentSeason = \App\Services\BattlePassService::SEASON;
        
        // All battle pass records for current season
        $allPasses = \App\Models\BattlePass::where('season', $currentSeason)->get();
        $totalWithBattlePass = $allPasses->count();
        
        // Premium (purchased) vs free
        $premiumPasses = $allPasses->where('premium', true);
        $totalPremium = $premiumPasses->count();
        $totalFree = $totalWithBattlePass - $totalPremium;
        
        // Average tier calculations
        $avgTierAll = $totalWithBattlePass > 0 ? round($allPasses->avg('tier'), 1) : 0;
        $avgTierPremium = $totalPremium > 0 ? round($premiumPasses->avg('tier'), 1) : 0;
        $avgTierFree = $totalFree > 0 ? round($allPasses->where('premium', false)->avg('tier'), 1) : 0;
        
        // Completion metrics
        $maxTier = \App\Services\BattlePassService::TOTAL_TIERS;
        $completedPasses = $allPasses->where('tier', '>=', $maxTier)->count();
        $completionRate = $totalWithBattlePass > 0 ? round($completedPasses / $totalWithBattlePass * 100, 1) : 0;
        
        // Tier distribution (useful for seeing engagement drop-off)
        $tierBuckets = [
            'tier_0_9' => $allPasses->whereBetween('tier', [0, 9])->count(),
            'tier_10_24' => $allPasses->whereBetween('tier', [10, 24])->count(),
            'tier_25_49' => $allPasses->whereBetween('tier', [25, 49])->count(),
            'tier_50_74' => $allPasses->whereBetween('tier', [50, 74])->count(),
            'tier_75_99' => $allPasses->whereBetween('tier', [75, 99])->count(),
            'tier_100' => $allPasses->where('tier', '>=', 100)->count(),
        ];
        
        // Premium conversion rate (of those who have started the battle pass)
        $premiumConversionRate = $totalWithBattlePass > 0 ? round($totalPremium / $totalWithBattlePass * 100, 1) : 0;

        return [
            'season' => $currentSeason,
            'total_active' => $totalWithBattlePass,
            'total_premium' => $totalPremium,
            'total_free' => $totalFree,
            'avg_tier_all' => $avgTierAll,
            'avg_tier_premium' => $avgTierPremium,
            'avg_tier_free' => $avgTierFree,
            'completed_count' => $completedPasses,
            'completion_rate_pct' => $completionRate,
            'premium_conversion_pct' => $premiumConversionRate,
            'tier_distribution' => $tierBuckets,
        ];
    }

    /** Premium battle pass purchase breakdown — tracks how players unlock premium (gems vs real money)
     * and the total value derived from each method. Useful for understanding monetization channels and
     * the effectiveness of the gem-purchase option alongside direct cash sales. */
    private function battlePassPremiumStats(): array
    {
        $currentSeason = \App\Services\BattlePassService::SEASON;
        $premiumGemCost = \App\Http\Controllers\Api\BattlePassController::PREMIUM_GEM_COST;
        
        // Count gem-based unlocks from GemLedger (negative delta = spent gems)
        $gemUnlocks = GemLedger::where('reason', 'battlepass_premium_unlock')
            ->where('delta', -$premiumGemCost)
            ->count();
        
        // Count cash-based unlocks from Purchase table (SKU: pass_ashfall or pass_{season})
        $cashPurchases = Purchase::where('status', 'completed')
            ->where(function ($q) use ($currentSeason) {
                $q->where('sku', "pass_{$currentSeason}")
                  ->orWhere('sku', 'pass_ashfall'); // Fallback for current season
            })
            ->get();
        
        $cashUnlocks = $cashPurchases->count();
        $cashRevenueCents = $cashPurchases->sum('amount_cents');
        
        // Total premium unlocks
        $totalPremiumUnlocks = $gemUnlocks + $cashUnlocks;
        
        // Calculate percentages
        $gemUnlockPct = $totalPremiumUnlocks > 0 ? round($gemUnlocks / $totalPremiumUnlocks * 100, 1) : 0;
        $cashUnlockPct = $totalPremiumUnlocks > 0 ? round($cashUnlocks / $totalPremiumUnlocks * 100, 1) : 0;
        
        // Calculate gem value (gems spent)
        $totalGemsSpent = $gemUnlocks * $premiumGemCost;
        
        // Average revenue per cash unlock
        $avgRevenueCents = $cashUnlocks > 0 ? round($cashRevenueCents / $cashUnlocks) : 0;

        return [
            'season' => $currentSeason,
            'total_premium_unlocks' => $totalPremiumUnlocks,
            'gem_unlocks' => [
                'count' => $gemUnlocks,
                'percentage' => $gemUnlockPct,
                'total_gems_spent' => $totalGemsSpent,
                'gems_per_unlock' => $premiumGemCost,
            ],
            'cash_unlocks' => [
                'count' => $cashUnlocks,
                'percentage' => $cashUnlockPct,
                'revenue_cents' => $cashRevenueCents,
                'avg_revenue_cents' => $avgRevenueCents,
            ],
            'breakdown' => [
                ['method' => 'Gems', 'count' => $gemUnlocks, 'percentage' => $gemUnlockPct],
                ['method' => 'Cash', 'count' => $cashUnlocks, 'percentage' => $cashUnlockPct],
            ],
        ];
    }
}
