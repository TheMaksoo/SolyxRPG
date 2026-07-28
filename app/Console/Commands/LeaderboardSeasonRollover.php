<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\CharacterCosmetic;
use App\Models\Cosmetic;
use App\Models\GemLedger;
use App\Models\LeaderboardHallOfFame;
use App\Models\LeaderboardSeason;
use App\Models\Mail;
use App\Services\LeaderboardService;
use Illuminate\Console\Command;

class LeaderboardSeasonRollover extends Command
{
    protected $signature = 'leaderboard:season-rollover';

    protected $description = 'Ends the active leaderboard season if its end date has passed: pays out rewards ranked by PvP Trophies, archives the top 10 into the Hall of Fame, and starts the next season. A no-op otherwise, so it is safe to schedule daily.';

    public function handle(LeaderboardService $leaderboard): int
    {
        $season = $leaderboard->currentSeason();

        if ($season->ends_at->isFuture()) {
            $this->info('Current season still active — nothing to do.');

            return self::SUCCESS;
        }

        $ranking = $leaderboard->fullRanking('trophies')->take(100);
        $granted = 0;

        foreach ($ranking as $i => $row) {
            $rank = $i + 1;
            $tier = collect(LeaderboardService::REWARD_TIERS)->first(fn ($t) => $rank >= $t['from'] && $rank <= $t['to']);
            if (! $tier) {
                continue;
            }

            $character = Character::find($row['character_id']);
            if (! $character) {
                continue;
            }

            $rewardSummary = $this->grantReward($character, $season, $rank, $tier, (int) $row['value']);
            $granted++;

            if ($rank <= 10) {
                LeaderboardHallOfFame::create([
                    'season_id' => $season->id,
                    'rank' => $rank,
                    'character_id' => $character->id,
                    'character_name' => $character->name,
                    'value' => $row['value'],
                    'reward_summary' => $rewardSummary,
                    'created_at' => now(),
                ]);
            }
        }

        $next = $this->startNextSeason($season);
        $leaderboard->snapshotAllCategories('season', (string) $next->id);

        $this->info("Season {$season->season_number} rolled over to Season {$next->season_number}. Rewarded {$granted} character(s).");

        return self::SUCCESS;
    }

    /** Grants gold/gems (every tier) plus a title (champion only) or banner (rest of the top 10) cosmetic
     * unlock — unlock-only, matching PvpSeasonReset's precedent of never auto-equipping a reward — then
     * mails the character a summary. Returns the reward summary string for Hall of Fame archival. */
    private function grantReward(Character $character, LeaderboardSeason $season, int $rank, array $tier, int $value): string
    {
        $character->increment('gold', $tier['gold']);
        $rewardParts = ["{$tier['gold']} gold"];

        if ($tier['gems'] > 0) {
            $character->increment('gems', $tier['gems']);
            GemLedger::log($character, $tier['gems'], "leaderboard_season_reward:s{$season->season_number}:rank{$rank}");
            $rewardParts[] = "{$tier['gems']} gems";
        }

        if ($tier['title']) {
            $this->unlockCosmetic($character, "title_leaderboard_champion_s{$season->season_number}", 'title', "Season {$season->season_number} Champion", "👑 Season {$season->season_number} Champion", 'legendary');
            $rewardParts[] = 'the "Season Champion" title';
        }

        if ($tier['banner']) {
            $this->unlockCosmetic($character, "banner_leaderboard_top10_s{$season->season_number}", 'banner', "Season {$season->season_number} Top 10", 'linear-gradient(135deg, rgba(234,179,8,.25), rgba(232,72,47,.15))', 'epic');
            $rewardParts[] = 'a Top 10 banner';
        }

        $rewardSummary = implode(', ', $rewardParts);

        Mail::create([
            'recipient_user_id' => $character->user_id,
            'subject' => "Season {$season->season_number} results: you placed #{$rank}!",
            'body' => "The leaderboard season has ended. You placed #{$rank} in PvP with {$value} rating, earning {$rewardSummary}.",
            'created_at' => now(),
        ]);

        return $rewardSummary;
    }

    private function unlockCosmetic(Character $character, string $key, string $type, string $name, string $value, string $rarity): void
    {
        $cosmetic = Cosmetic::firstOrCreate(
            ['key' => $key],
            ['type' => $type, 'name' => $name, 'value' => $value, 'rarity' => $rarity, 'category' => 'season', 'enabled' => true]
        );

        CharacterCosmetic::firstOrCreate(
            ['character_id' => $character->id, 'cosmetic_id' => $cosmetic->id],
            ['unlocked_at' => now()]
        );
    }

    private function startNextSeason(LeaderboardSeason $season): LeaderboardSeason
    {
        $season->update(['is_active' => false]);

        $nextNumber = $season->season_number + 1;

        return LeaderboardSeason::create([
            'season_number' => $nextNumber,
            'name' => "Season {$nextNumber}",
            'starts_at' => now(),
            // Calendar-month length, auto-incrementing — see LeaderboardService::currentSeason().
            'ends_at' => now()->addMonthNoOverflow(),
            'is_active' => true,
        ]);
    }
}
