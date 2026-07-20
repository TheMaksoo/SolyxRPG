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
}
