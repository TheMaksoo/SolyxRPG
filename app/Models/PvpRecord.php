<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpRecord extends Model
{
    protected $fillable = ['character_id', 'rating', 'wins', 'losses', 'win_streak'];

    /** Fixed-name rating tiers (distinct from the percentile bracket system above), ordered lowest to
     * highest. Ratings start at 1000 (see PvpController::index), which should land solidly in Silver. */
    public const PVP_TIERS = [
        ['name' => 'Bronze', 'min_rating' => 0, 'color' => '#b8794a'],
        ['name' => 'Silver', 'min_rating' => 900, 'color' => '#c0c0c0'],
        ['name' => 'Gold', 'min_rating' => 1100, 'color' => '#eab308'],
        ['name' => 'Platinum', 'min_rating' => 1300, 'color' => '#5cc7f5'],
        ['name' => 'Diamond', 'min_rating' => 1500, 'color' => '#a78bfa'],
        ['name' => 'Master', 'min_rating' => 1700, 'color' => '#e8482f'],
    ];

    /** The tier row matching a given rating (last tier whose min_rating is <= the rating). */
    public static function tierFor(int $rating): array
    {
        $match = static::PVP_TIERS[0];
        foreach (static::PVP_TIERS as $tier) {
            if ($rating >= $tier['min_rating']) {
                $match = $tier;
            }
        }

        return $match;
    }

    /** Progress from the current tier toward the next one, as a 0-100 percentage of the rating gap. */
    public static function tierProgress(int $rating): array
    {
        $tiers = static::PVP_TIERS;
        $current = static::tierFor($rating);
        $currentIndex = array_search($current, $tiers);
        $next = $tiers[$currentIndex + 1] ?? null;

        if (! $next) {
            return ['current' => $current, 'next' => null, 'pct' => 100];
        }

        $span = $next['min_rating'] - $current['min_rating'];
        $pct = $span > 0 ? (($rating - $current['min_rating']) / $span) * 100 : 100;

        return ['current' => $current, 'next' => $next, 'pct' => max(0, min(100, round($pct, 1)))];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** This record's rank against every other ranked player right now, as a percentile bracket rather than
     * a fixed rating threshold — "Top 5%" always means the top 5% of the current ladder, so it doesn't
     * drift as the whole population's rating inflates or deflates over a season. */
    public function percentileBracket(): string
    {
        return static::bracketFromRatings($this->rating, static::pluck('rating')->all());
    }

    /** Percentile bracket for one rating against an already-fetched population of ratings — avoids an
     * extra query per record when computing brackets for a whole list at once (see PvpController). */
    public static function bracketFromRatings(int $rating, array $allRatings): string
    {
        $total = count($allRatings);
        if ($total <= 1) {
            return static::bracketLabel(0);
        }

        $better = 0;
        foreach ($allRatings as $r) {
            if ($r > $rating) {
                $better++;
            }
        }

        return static::bracketLabel(($better / $total) * 100);
    }

    /** Maps a 0 (best) - 100 (worst) percentile to its bracket label. */
    public static function bracketLabel(float $percentile): string
    {
        return match (true) {
            $percentile < 1 => 'Top 1%',
            $percentile < 5 => 'Top 5%',
            $percentile < 10 => 'Top 10%',
            $percentile < 25 => 'Top 25%',
            $percentile < 50 => 'Top 50%',
            $percentile < 80 => 'Top 80%',
            default => 'Top 100%',
        };
    }

    /** Raw percentile (0 = best, 100 = worst) for one rating against an already-fetched population —
     * the same "how many people beat me" math bracketFromRatings uses, just returned as a number instead
     * of pre-formatted into a label, so hybridRank()/hybridProgress() can bucket it themselves. */
    public static function percentileFor(int $rating, array $allRatings): float
    {
        $total = count($allRatings);
        if ($total <= 1) {
            return 0.0;
        }

        $better = 0;
        foreach ($allRatings as $r) {
            if ($r > $rating) {
                $better++;
            }
        }

        return ($better / $total) * 100;
    }

    /** The single merged PvP rank: bucket the player's live percentile against the current ladder into
     * one of the six existing tier names/colors (reusing PVP_TIERS rather than inventing a parallel naming
     * scheme), so "your rank" is always relative to how everyone else is doing right now instead of a
     * fixed rating line that drifts as the population inflates/deflates. The outright #1 player on the
     * ladder (rank position 1, ties included) gets a distinct "<Tier> — Rank #1" crown on top of whatever
     * bucket their percentile lands in (which for the literal best player is always Master anyway, since
     * position 1 implies top ~0%). Cutoffs: top 1% => Master, top 5% => Diamond, top 10% => Platinum,
     * top 25% => Gold, top 50% => Silver, everyone else => Bronze. */
    public static function hybridRank(int $rating, array $allRatings): array
    {
        $total = count($allRatings);
        $better = 0;
        foreach ($allRatings as $r) {
            if ($r > $rating) {
                $better++;
            }
        }
        $percentile = $total > 1 ? ($better / $total) * 100 : 0.0;
        $position = $better + 1;

        $tiers = static::PVP_TIERS;
        $tier = match (true) {
            $percentile < 1 => $tiers[5], // Master
            $percentile < 5 => $tiers[4], // Diamond
            $percentile < 10 => $tiers[3], // Platinum
            $percentile < 25 => $tiers[2], // Gold
            $percentile < 50 => $tiers[1], // Silver
            default => $tiers[0], // Bronze
        };

        $isRankOne = $total > 0 && $position === 1;

        return [
            'name' => $isRankOne ? "{$tier['name']} — Rank #1" : $tier['name'],
            'base_name' => $tier['name'],
            'color' => $tier['color'],
            'glyph' => $isRankOne ? '👑' : '⚔',
            'percentile' => round($percentile, 2),
            'percentile_label' => static::bracketLabel($percentile),
            'rank_position' => $position,
            'is_rank_one' => $isRankOne,
        ];
    }

    /** Progress from the current hybrid-rank bracket toward the next better one, as a 0-100% of the
     * percentile gap — mirrors tierProgress() but walks the percentile ladder instead of the rating ladder. */
    public static function hybridProgress(float $percentile): array
    {
        $tiers = static::PVP_TIERS;
        // Percentile [floor, ceiling) for each tier index, worst (Bronze) to best (Master).
        $ceilings = [100, 50, 25, 10, 5, 1];
        $floors = [50, 25, 10, 5, 1, 0];

        $index = match (true) {
            $percentile < 1 => 5,
            $percentile < 5 => 4,
            $percentile < 10 => 3,
            $percentile < 25 => 2,
            $percentile < 50 => 1,
            default => 0,
        };

        if ($index === 5) {
            return ['current' => $tiers[5], 'next' => null, 'pct' => 100.0];
        }

        $span = $ceilings[$index] - $floors[$index];
        $pct = $span > 0 ? (($ceilings[$index] - $percentile) / $span) * 100 : 100;

        return ['current' => $tiers[$index], 'next' => $tiers[$index + 1], 'pct' => max(0, min(100, round($pct, 1)))];
    }
}
