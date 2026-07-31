<?php

namespace App\Console\Commands;

use App\Models\CharacterCosmetic;
use App\Models\Cosmetic;
use App\Models\GameConfig;
use App\Models\PvpRecord;
use Illuminate\Console\Command;

class PvpSeasonReset extends Command
{
    protected $signature = 'pvp:season-reset';

    protected $description = 'Ends the current PvP season: grants season-exclusive title cosmetics to Platinum+ players (plus an avatar frame for Master), then soft-resets ratings toward the 1000 baseline. Scheduled for the 1st of every real calendar month.';

    /** Rarity per tier for the season-exclusive title — Master is the top tier so it's a step above the
     * other two, matching how the Leaderboard season's own top-rank rewards are tiered. */
    private const TIER_RARITY = [
        'Platinum' => 'legendary',
        'Diamond' => 'legendary',
        'Master' => 'mythic',
    ];

    public function handle(): int
    {
        $season = (int) GameConfig::number('pvp_season_number', 1);
        $rewardableTiers = array_keys(self::TIER_RARITY);
        $granted = 0;

        PvpRecord::all()->each(function (PvpRecord $record) use ($rewardableTiers, $season, &$granted) {
            $tier = PvpRecord::tierFor($record->rating);

            if (in_array($tier['name'], $rewardableTiers, true)) {
                $this->grantSeasonTitle($record, $tier['name'], $season);
                $granted++;
            }
            if ($tier['name'] === 'Master') {
                $this->grantSeasonFrame($record, $season);
            }

            // Soft-reset: halve the distance above the 1000 baseline, floor at 1000. Wins/losses/win_streak
            // are left untouched — this is a rating reset, not a stats wipe.
            $record->rating = max(1000, 1000 + intdiv($record->rating - 1000, 2));
            $record->save();
        });

        GameConfig::updateOrCreate(['key' => 'pvp_season_number'], ['value' => (string) ($season + 1)]);

        $this->info("Season {$season} reset complete. Granted {$granted} season-exclusive title(s). Ratings soft-reset toward 1000. Next run will be Season ".($season + 1).'.');

        return self::SUCCESS;
    }

    /** Creates this season's title cosmetic on the fly (mirrors LeaderboardSeasonRollover::unlockCosmetic)
     * instead of depending on it being pre-seeded — so season 2, 3, etc. grant a real reward automatically
     * every month instead of silently granting nothing once the pre-seeded Season 1 titles run out. */
    private function grantSeasonTitle(PvpRecord $record, string $tierName, int $season): void
    {
        $lower = strtolower($tierName);
        $cosmetic = Cosmetic::firstOrCreate(
            ['key' => "title_pvp_{$lower}_s{$season}"],
            [
                'type' => 'title',
                'name' => "{$tierName} Gladiator (S{$season})",
                'value' => "{$tierName} Gladiator",
                'rarity' => self::TIER_RARITY[$tierName],
                'category' => 'event',
                'cost_gems' => 0,
                'unlock_event' => "pvp_season_{$season}_{$lower}",
                'enabled' => true,
                // Season-exclusive, per-rank-tier reward — hidden from anyone who hasn't earned it
                // (CosmeticController::index()) rather than listed as a browsable "locked" catalog entry.
                'is_dynamic' => true,
            ]
        );

        CharacterCosmetic::firstOrCreate(
            ['character_id' => $record->character_id, 'cosmetic_id' => $cosmetic->id],
            ['unlocked_at' => now()]
        );
    }

    /** Master-tier-only avatar frame, on top of the title every rewardable tier gets — the single hardest
     * PvP bracket to reach gets an extra flex, same "unlock-only, never auto-equipped" rule as everything
     * else here. */
    private function grantSeasonFrame(PvpRecord $record, int $season): void
    {
        $cosmetic = Cosmetic::firstOrCreate(
            ['key' => "frame_pvp_master_s{$season}"],
            [
                'type' => 'frame',
                'name' => "Master Gladiator Frame (S{$season})",
                'value' => '#a855f7',
                'rarity' => 'mythic',
                'category' => 'event',
                'cost_gems' => 0,
                'unlock_event' => "pvp_season_{$season}_master",
                'enabled' => true,
                'is_dynamic' => true,
            ]
        );

        CharacterCosmetic::firstOrCreate(
            ['character_id' => $record->character_id, 'cosmetic_id' => $cosmetic->id],
            ['unlocked_at' => now()]
        );
    }
}
