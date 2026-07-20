<?php

namespace App\Console\Commands;

use App\Models\CharacterCosmetic;
use App\Models\Cosmetic;
use App\Models\PvpRecord;
use Illuminate\Console\Command;

class PvpSeasonReset extends Command
{
    protected $signature = 'pvp:season-reset';

    protected $description = 'Ends the current PvP season: grants season-exclusive title cosmetics to Platinum+ players, then soft-resets ratings toward the 1000 baseline.';

    /** Season number is hardcoded for now — bump this (and the cosmetic keys/unlock_event values in
     * CosmeticSeeder) when a new season's exclusive rewards are introduced. */
    private const SEASON = 1;

    /** Tier name => season-exclusive title cosmetic key granted for finishing the season at that tier or above. */
    private const TIER_TITLE_KEYS = [
        'Platinum' => 'title_pvp_platinum_s1',
        'Diamond' => 'title_pvp_diamond_s1',
        'Master' => 'title_pvp_master_s1',
    ];

    public function handle(): int
    {
        $rewardableTiers = array_keys(self::TIER_TITLE_KEYS);
        $granted = 0;

        PvpRecord::all()->each(function (PvpRecord $record) use ($rewardableTiers, &$granted) {
            $tier = PvpRecord::tierFor($record->rating);

            if (in_array($tier['name'], $rewardableTiers, true)) {
                $cosmeticKey = self::TIER_TITLE_KEYS[$tier['name']];
                $cosmeticId = Cosmetic::where('key', $cosmeticKey)->value('id');

                if ($cosmeticId) {
                    CharacterCosmetic::firstOrCreate(
                        ['character_id' => $record->character_id, 'cosmetic_id' => $cosmeticId],
                        ['unlocked_at' => now()]
                    );
                    $granted++;
                }
            }

            // Soft-reset: halve the distance above the 1000 baseline, floor at 1000. Wins/losses/win_streak
            // are left untouched — this is a rating reset, not a stats wipe.
            $record->rating = max(1000, 1000 + intdiv($record->rating - 1000, 2));
            $record->save();
        });

        $season = self::SEASON;
        $this->info("Season {$season} reset complete. Granted {$granted} season-exclusive title(s). Ratings soft-reset toward 1000.");

        return self::SUCCESS;
    }
}
