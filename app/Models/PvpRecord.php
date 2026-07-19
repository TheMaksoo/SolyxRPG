<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpRecord extends Model
{
    protected $fillable = ['character_id', 'rating', 'wins', 'losses', 'win_streak'];

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
