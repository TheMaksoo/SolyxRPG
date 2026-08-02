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

class LeaderboardDistributeRewards extends Command
{
    protected $signature = 'leaderboard:distribute-rewards {season_number?} {--force : Skip confirmation prompt}';

    protected $description = 'Retroactively distributes leaderboard season-end rewards (gold, gems, titles, banners) for a specific season. Useful when the automatic rollover failed to run.';

    public function handle(LeaderboardService $leaderboard): int
    {
        $seasonNumber = $this->argument('season_number');

        // If no season specified, use the most recent non-active season (or current if forced)
        if (!$seasonNumber) {
            $season = LeaderboardSeason::where('is_active', false)
                ->orderBy('season_number', 'desc')
                ->first();

            if (!$season) {
                $season = $leaderboard->currentSeason();
                $this->warn('No completed seasons found. Using current active season.');
            }

            $seasonNumber = $season->season_number;
        }

        $season = LeaderboardSeason::where('season_number', $seasonNumber)->first();

        if (!$season) {
            $this->error("Season {$seasonNumber} not found in the database.");
            return self::FAILURE;
        }

        $this->info("Processing season {$season->season_number} rewards...");
        $this->info("Season: {$season->name}");
        $this->info("Dates: {$season->starts_at->format('Y-m-d')} to {$season->ends_at->format('Y-m-d')}");
        $this->newLine();

        // Check if rewards might have already been granted
        $existingHofCount = LeaderboardHallOfFame::where('season_id', $season->id)->count();
        if ($existingHofCount > 0) {
            $this->warn("⚠️  Found {$existingHofCount} Hall of Fame entries for this season - rewards may have already been granted.");
            if (!$this->option('force')) {
                if (!$this->confirm('Continue anyway? This could result in duplicate rewards.')) {
                    $this->info('Distribution cancelled.');
                    return self::FAILURE;
                }
            }
        }

        // Get rankings for all categories
        $this->info('Fetching leaderboard rankings...');
        
        // 1. Grant Trophies rewards (gold, gems, cosmetics)
        $trophiesRanking = $leaderboard->fullRanking('trophies')->take(100);
        $this->info("Found {$trophiesRanking->count()} characters in Trophies leaderboard.");

        // 2. Get all category rankings for titles and banners
        $categoryStats = [];
        foreach ($leaderboard->trackedCategories() as $categoryKey) {
            $ranking = $leaderboard->fullRanking($categoryKey)->take(100);
            $categoryStats[$categoryKey] = $ranking->count();
        }

        $this->newLine();
        $this->info('Reward distribution plan:');
        $this->line("  • Trophies rewards (gold/gems): Top {$trophiesRanking->count()}");
        $this->line("  • Category titles: Top 10 in each of ".count($leaderboard->trackedCategories())." categories");
        $this->line("  • Season banners: Top 100 in each category");
        $this->newLine();

        if (!$this->option('force')) {
            if (!$this->confirm('Proceed with reward distribution?')) {
                $this->info('Distribution cancelled.');
                return self::FAILURE;
            }
        }

        $this->info('Distributing rewards...');
        $this->newLine();

        // Step 1: Grant Trophies rewards (gold, gems, titles, banners)
        $this->info('Step 1/3: Granting Trophies rewards (gold, gems, cosmetics)...');
        $trophiesGranted = $this->grantTrophiesRewards($leaderboard, $season, $trophiesRanking);
        $this->info("  ✓ Granted rewards to {$trophiesGranted} character(s)");

        // Step 2: Grant category titles
        $this->info('Step 2/3: Granting category titles...');
        $titlesGranted = $this->grantCategoryTitles($leaderboard, $season);
        $this->info("  ✓ Granted {$titlesGranted} category title(s)");

        // Step 3: Grant season banners
        $this->info('Step 3/3: Granting season banners...');
        $bannersGranted = $this->grantSeasonBanners($leaderboard, $season);
        $this->info("  ✓ Granted {$bannersGranted} season banner(s)");

        $this->newLine();
        $this->info("✅ Successfully distributed season {$season->season_number} rewards!");
        $this->info("   Trophies rewards: {$trophiesGranted}");
        $this->info("   Category titles: {$titlesGranted}");
        $this->info("   Season banners: {$bannersGranted}");

        return self::SUCCESS;
    }

    private function grantTrophiesRewards(LeaderboardService $leaderboard, LeaderboardSeason $season, $ranking): int
    {
        $granted = 0;

        foreach ($ranking as $i => $row) {
            $rank = $i + 1;
            $tier = collect(LeaderboardService::REWARD_TIERS)->first(fn ($t) => $rank >= $t['from'] && $rank <= $t['to']);
            if (!$tier) {
                continue;
            }

            $character = Character::find($row['character_id']);
            if (!$character || !$character->user) {
                continue;
            }

            $rewardSummary = $this->grantReward($character, $season, $rank, $tier, (int) $row['value']);
            $granted++;

            // Archive top 10 to Hall of Fame (if not already there)
            if ($rank <= 10) {
                LeaderboardHallOfFame::firstOrCreate(
                    [
                        'season_id' => $season->id,
                        'character_id' => $character->id,
                    ],
                    [
                        'rank' => $rank,
                        'character_name' => $character->name,
                        'value' => $row['value'],
                        'reward_summary' => $rewardSummary,
                        'created_at' => now(),
                    ]
                );
            }
        }

        return $granted;
    }

    private function grantReward(Character $character, LeaderboardSeason $season, int $rank, array $tier, int $value): string
    {
        $character->increment('gold', $tier['gold']);
        $rewardParts = ["{$tier['gold']} gold"];

        if ($tier['gems'] > 0 && $character->user) {
            $character->user->increment('gems', $tier['gems']);
            GemLedger::log($character->user, $tier['gems'], "leaderboard_season_reward:s{$season->season_number}:rank{$rank}", $character);
            $rewardParts[] = "{$tier['gems']} gems";
        }

        if ($tier['title']) {
            $this->unlockCosmetic(
                $character, "title_leaderboard_champion_s{$season->season_number}", 'title',
                "Season {$season->season_number} Champion", "👑 Season {$season->season_number} Champion",
                ['rarity' => 'legendary', 'event' => "leaderboard_trophies_champion:{$season->season_number}"]
            );
            $rewardParts[] = 'the "Season Champion" title';
        }

        if ($tier['banner']) {
            $this->unlockCosmetic(
                $character, "banner_leaderboard_top10_s{$season->season_number}", 'banner',
                "Season {$season->season_number} Top 10", 'linear-gradient(135deg, rgba(234,179,8,.25), rgba(232,72,47,.15))',
                [
                    'rarity' => 'epic', 'event' => "leaderboard_trophies_top10_banner:{$season->season_number}",
                    'icon' => '🎖', 'accent_hex' => '#eab308',
                ]
            );
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

    private function grantCategoryTitles(LeaderboardService $leaderboard, LeaderboardSeason $season): int
    {
        $granted = 0;

        foreach ($leaderboard->trackedCategories() as $categoryKey) {
            $label = LeaderboardService::CATEGORIES[$categoryKey]['label'];
            $topTen = $leaderboard->fullRanking($categoryKey)->take(10);

            foreach ($topTen as $i => $row) {
                $rank = $i + 1;
                $character = Character::find($row['character_id']);
                if (!$character || !$character->user) {
                    continue;
                }

                if ($rank === 1) {
                    $this->unlockCosmetic(
                        $character,
                        "title_leaderboard_{$categoryKey}_champion_s{$season->season_number}",
                        'title', "{$label} Champion (S{$season->season_number})", "🏆 Champion of {$label}",
                        ['rarity' => 'legendary', 'event' => "leaderboard_category_champion:{$categoryKey}:{$season->season_number}"]
                    );
                } else {
                    $this->unlockCosmetic(
                        $character,
                        "title_leaderboard_{$categoryKey}_top10_s{$season->season_number}",
                        'title', "{$label} Top 10 (S{$season->season_number})", "🎖 Top 10 — {$label}",
                        ['rarity' => 'epic', 'event' => "leaderboard_category_top10:{$categoryKey}:{$season->season_number}"]
                    );
                }

                $granted++;
            }
        }

        return $granted;
    }

    private function grantSeasonBanners(LeaderboardService $leaderboard, LeaderboardSeason $season): int
    {
        $tierLabels = ['champion' => '#1 Champion', 'rank2' => '#2', 'rank3' => '#3', 'top10' => 'Top 10', 'top100' => 'Top 100'];
        $tierRarities = ['champion' => 'legendary', 'rank2' => 'epic', 'rank3' => 'epic', 'top10' => 'rare', 'top100' => 'common'];
        $granted = 0;

        $categoryHex = [
            'power' => '#e8482f', 'level' => '#60a5fa', 'trophies' => '#eab308', 'monsters_slain' => '#e8482f',
            'bosses_slain' => '#dc2626', 'deaths' => '#7c8794',
            'gold' => '#eab308', 'gems' => '#60a5fa', 'quests_completed' => '#4ade80', 'battle_pass_points' => '#f59e0b',
            'times_mined' => '#7c8794', 'times_chopped' => '#84cc16', 'times_smelted' => '#e8482f',
            'times_foraged' => '#22c55e', 'times_crafted' => '#a78bfa',
            'dungeon_clears' => '#a855f7', 'companions_collected' => '#f472b6', 'companion_ranks' => '#fb923c',
        ];

        foreach ($leaderboard->trackedCategories() as $categoryKey) {
            $label = LeaderboardService::CATEGORIES[$categoryKey]['label'];
            $ranking = $leaderboard->fullRanking($categoryKey)->take(100);

            foreach ($ranking as $i => $row) {
                $rank = $i + 1;
                $tier = $this->seasonBannerTier($rank);
                if (!$tier) {
                    continue;
                }

                $character = Character::find($row['character_id']);
                if (!$character || !$character->user) {
                    continue;
                }

                $this->unlockCosmetic(
                    $character,
                    "banner_season_{$categoryKey}_{$tier}_s{$season->season_number}",
                    'banner',
                    "{$label} — S{$season->season_number} {$tierLabels[$tier]}",
                    $this->seasonBannerGradient($categoryKey, $tier, $season->season_number, $categoryHex),
                    [
                        'rarity' => $tierRarities[$tier],
                        'event' => "leaderboard_season_banner:{$categoryKey}:{$tier}:{$season->season_number}",
                        'icon' => LeaderboardService::CATEGORIES[$categoryKey]['glyph'] ?? '🏆',
                        'accent_hex' => $categoryHex[$categoryKey] ?? '#e8482f',
                    ]
                );
                $granted++;
            }
        }

        return $granted;
    }

    private function seasonBannerTier(int $rank): ?string
    {
        return match (true) {
            $rank === 1 => 'champion',
            $rank === 2 => 'rank2',
            $rank === 3 => 'rank3',
            $rank <= 10 => 'top10',
            $rank <= 100 => 'top100',
            default => null,
        };
    }

    private function seasonBannerGradient(string $categoryKey, string $tier, int $seasonNumber, array $categoryHex): string
    {
        $tierGlow = [
            'champion' => 'rgba(245,197,66,.55)', 
            'rank2' => 'rgba(212,216,224,.42)',
            'rank3' => 'rgba(212,216,224,.42)',
            'top10' => 'rgba(200,128,74,.4)', 
            'top100' => 'rgba(124,135,148,.3)',
        ];
        $seasonTints = ['#e8482f', '#60a5fa', '#4ade80', '#eab308', '#a855f7', '#f97316', '#ec4899', '#14b8a6'];

        $hex = $categoryHex[$categoryKey] ?? '#e8482f';
        $glow = $tierGlow[$tier];
        $seasonHex = $seasonTints[($seasonNumber - 1) % count($seasonTints)];
        $seasonX = 20 + (($seasonNumber * 37) % 60);
        $seasonY = ($seasonNumber * 53) % 40;

        return "radial-gradient(farthest-corner at {$seasonX}% {$seasonY}%,{$seasonHex}3d,transparent 55%),"
            ."radial-gradient(farthest-corner at 16% 0%,{$glow},transparent 58%),"
            ."radial-gradient(farthest-corner at 88% 100%,{$hex}66,transparent 62%),"
            .'linear-gradient(180deg,#17131a,#0b0b0c)';
    }

    private function unlockCosmetic(Character $character, string $key, string $type, string $name, string $value, array $meta): void
    {
        $cosmetic = Cosmetic::firstOrCreate(
            ['key' => $key],
            [
                'type' => $type, 'name' => $name, 'value' => $value,
                'icon' => $meta['icon'] ?? null, 'accent_hex' => $meta['accent_hex'] ?? null,
                'rarity' => $meta['rarity'], 'category' => 'season', 'enabled' => true,
                'unlock_event' => $meta['event'] ?? null, 'is_dynamic' => true,
            ]
        );

        CharacterCosmetic::firstOrCreate(
            ['character_id' => $character->id, 'cosmetic_id' => $cosmetic->id],
            ['unlocked_at' => now()]
        );
    }
}
