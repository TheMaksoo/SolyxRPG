<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Character;
use App\Models\CharacterQuest;
use App\Models\CraftingJob;
use App\Models\Dungeon;
use App\Models\DungeonRun;
use App\Models\ErrorLog;
use App\Models\Monster;
use App\Models\PvpLiveMatch;
use App\Models\Recipe;
use App\Models\Skill;
use App\Models\SupportTicket;
use App\Models\TradeSkillLog;
use App\Models\User;
use App\Models\Zone;
use App\Services\ReferralService;
use Illuminate\Http\Request;

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
            'level_growth' => $this->levelGrowth(),
            'kills_to_level_up' => $this->killsToLevelUp(),
            'unlock_timeline' => $this->unlockTimeline(),
            'content_interest' => $this->contentInterest($since),
            'class_distribution' => Character::select('base_class')
                ->selectRaw('count(*) as count')
                ->groupBy('base_class')
                ->pluck('count', 'base_class'),
            'level_distribution' => $this->levelDistribution(),
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

    /** Cumulative XP required to reach each level, sampled every 5 levels through 150 — the top of
     * currently-authored content (see Character::MAX_LEVEL's comment: there's no real cap, but nothing
     * new unlocks past 150, it's just further attribute/skill grind on xpForLevel()'s linear curve). */
    private function levelGrowth(): array
    {
        $sampleEvery = 5;
        $topLevel = 150;

        $cumulative = 0;
        $labels = [];
        $data = [];

        for ($level = 1; $level <= $topLevel; $level++) {
            if ($level > 1) {
                $cumulative += Character::xpForLevel($level - 1);
            }
            if ($level === 1 || $level % $sampleEvery === 0) {
                $labels[] = (string) $level;
                $data[] = $cumulative;
            }
        }

        return ['labels' => $labels, 'data' => $data];
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
        for ($level = 1; $level < 150; $level++) {
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

        $skills = Skill::all()->map(fn (Skill $s) => [
            'level' => $s->level_req,
            'name' => "{$s->name} ({$s->class_scope})",
            'type' => 'skill',
        ]);

        $recipes = Recipe::where('enabled', true)->get()->map(fn (Recipe $r) => [
            'level' => $r->min_level,
            'name' => $r->name,
            'type' => 'recipe',
        ]);

        return $zones->concat($dungeons)->concat($monsters)->concat($skills)->concat($recipes)
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

        return [
            'total_users' => User::count(),
            'total_characters' => Character::count(),
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
}
