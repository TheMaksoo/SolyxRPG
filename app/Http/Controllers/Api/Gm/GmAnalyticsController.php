<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Character;
use App\Models\CharacterQuest;
use App\Models\CraftingJob;
use App\Models\DungeonRun;
use App\Models\ErrorLog;
use App\Models\PvpLiveMatch;
use App\Models\SupportTicket;
use App\Models\TradeSkillLog;
use App\Models\User;
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
            ],
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
