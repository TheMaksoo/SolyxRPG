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

    protected $description = 'Ends the active leaderboard season if its end date has passed: pays out gold/gems/cosmetics ranked by PvP Trophies, grants a permanent Champion/Top 10 title for every other leaderboard category, archives the Trophies top 10 into the Hall of Fame, and starts the next season. A no-op otherwise, so it is safe to schedule daily.';

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

        $titlesGranted = $this->grantCategoryTitles($leaderboard, $season);
        $bannersGranted = $this->grantSeasonBanners($leaderboard, $season);

        $next = $this->startNextSeason($season);
        $leaderboard->snapshotAllCategories('season', (string) $next->id);

        $this->info("Season {$season->season_number} rolled over to Season {$next->season_number}. Rewarded {$granted} character(s) from Trophies, granted {$titlesGranted} category title(s), {$bannersGranted} season banner(s).");

        return self::SUCCESS;
    }

    /** Every tracked category (not just Trophies) permanently crowns its own top 10 each season — rank 1
     * gets "Champion of {Category}", ranks 2-10 get "Top 10 in {Category}". Unlock-only (never
     * auto-equipped) and keyed per season, so a character keeps every title they've ever earned even after
     * losing the rank next month — same permanence as every other Cosmetic/CharacterCosmetic unlock in the
     * game. Guild Power is skipped (trackedCategories() already excludes it) since it's a guild-wide gauge,
     * not a per-character ranking a title can attach to. */
    private function grantCategoryTitles(LeaderboardService $leaderboard, LeaderboardSeason $season): int
    {
        $granted = 0;

        foreach ($leaderboard->trackedCategories() as $categoryKey) {
            $label = LeaderboardService::CATEGORIES[$categoryKey]['label'];
            $topTen = $leaderboard->fullRanking($categoryKey)->take(10);

            foreach ($topTen as $i => $row) {
                $rank = $i + 1;
                $character = Character::find($row['character_id']);
                if (! $character) {
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

    /** Grants gold/gems (every tier) plus a title (champion only) or banner (rest of the top 10) cosmetic
     * unlock — unlock-only, matching PvpSeasonReset's precedent of never auto-equipping a reward — then
     * mails the character a summary. Returns the reward summary string for Hall of Fame archival. */
    private function grantReward(Character $character, LeaderboardSeason $season, int $rank, array $tier, int $value): string
    {
        $character->increment('gold', $tier['gold']);
        $rewardParts = ["{$tier['gold']} gold"];

        if ($tier['gems'] > 0) {
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

    /** Every tracked category's top-100 season finish becomes a real, permanent banner keyed by
     * (category, tier, season) — so a character can hold "Power S1 Top 1" AND "Power S3 Top 5"
     * simultaneously, one per season they actually placed in, matching a real placement history rather
     * than a single "current standing" badge. Tiers: rank 1 = champion, 2-3 = top3, 4-10 = top10,
     * 11-100 = top100. Only ever granted going forward from the season that just ended — no retroactive
     * backfill for seasons that completed before this feature existed, so nobody holds one they didn't
     * actually place for under this exact system. Guild Power has no per-character rank (trackedCategories()
     * already excludes it), so it can't produce a placement banner. */
    private function grantSeasonBanners(LeaderboardService $leaderboard, LeaderboardSeason $season): int
    {
        $tierLabels = ['champion' => '#1 Champion', 'top3' => 'Top 3', 'top10' => 'Top 10', 'top100' => 'Top 100'];
        $tierRarities = ['champion' => 'legendary', 'top3' => 'epic', 'top10' => 'rare', 'top100' => 'common'];
        $granted = 0;

        foreach ($leaderboard->trackedCategories() as $categoryKey) {
            $label = LeaderboardService::CATEGORIES[$categoryKey]['label'];
            $ranking = $leaderboard->fullRanking($categoryKey)->take(100);

            foreach ($ranking as $i => $row) {
                $rank = $i + 1;
                $tier = $this->seasonBannerTier($rank);
                if (! $tier) {
                    continue;
                }

                $character = Character::find($row['character_id']);
                if (! $character) {
                    continue;
                }

                $this->unlockCosmetic(
                    $character,
                    "banner_season_{$categoryKey}_{$tier}_s{$season->season_number}",
                    'banner',
                    "{$label} — S{$season->season_number} {$tierLabels[$tier]}",
                    $this->seasonBannerGradient($categoryKey, $tier, $season->season_number),
                    [
                        'rarity' => $tierRarities[$tier],
                        'event' => "leaderboard_season_banner:{$categoryKey}:{$tier}:{$season->season_number}",
                        'icon' => LeaderboardService::CATEGORIES[$categoryKey]['glyph'] ?? '🏆',
                        'accent_hex' => self::CATEGORY_HEX[$categoryKey] ?? '#e8482f',
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
            $rank <= 3 => 'top3',
            $rank <= 10 => 'top10',
            $rank <= 100 => 'top100',
            default => null,
        };
    }

    /** Per-category hue (not just per-group) so boards within the same group still stand apart —
     * Richest and Gem Hoarder are both WEALTH but read as gold vs blue, same as the design mock —
     * plus a per-tier glow and a large low-opacity laurel/crest glyph watermark for the higher tiers,
     * matching the mock's board-art treatment. */
    private const CATEGORY_HEX = [
        'power' => '#e8482f', 'level' => '#60a5fa', 'trophies' => '#eab308', 'monsters_slain' => '#e8482f',
        'bosses_slain' => '#dc2626', 'deaths' => '#7c8794',
        'gold' => '#eab308', 'gems' => '#60a5fa', 'quests_completed' => '#4ade80', 'battle_pass_points' => '#f59e0b',
        'times_mined' => '#7c8794', 'times_chopped' => '#84cc16', 'times_smelted' => '#e8482f',
        'times_foraged' => '#22c55e', 'times_crafted' => '#a78bfa',
        'dungeon_clears' => '#a855f7', 'companions_collected' => '#f472b6', 'companion_ranks' => '#fb923c',
    ];

    /** A rotating per-season tint (cycling every 8 seasons) layered in as a third radial stop, at a
     * season-derived position so two seasons sharing a tint (S1 and S9) still don't render identically —
     * the category hex stays the dominant/identity color (so the board is still recognizable at a
     * glance), the season tint + position is what visibly marks "this is a different season's banner". */
    private const SEASON_TINTS = ['#e8482f', '#60a5fa', '#4ade80', '#eab308', '#a855f7', '#f97316', '#ec4899', '#14b8a6'];

    private function seasonBannerGradient(string $categoryKey, string $tier, int $seasonNumber): string
    {
        $tierGlow = [
            'champion' => 'rgba(245,197,66,.55)', 'top3' => 'rgba(212,216,224,.42)',
            'top10' => 'rgba(200,128,74,.4)', 'top100' => 'rgba(124,135,148,.3)',
        ];
        $hex = self::CATEGORY_HEX[$categoryKey] ?? '#e8482f';
        $glow = $tierGlow[$tier];
        $seasonHex = self::SEASON_TINTS[($seasonNumber - 1) % count(self::SEASON_TINTS)];
        $seasonX = 20 + (($seasonNumber * 37) % 60);
        $seasonY = ($seasonNumber * 53) % 40;

        // `farthest-corner` (rather than a fixed percentage size) keeps each stop's fade reaching the
        // edge of the box no matter its aspect ratio — this same string paints both the short Customize
        // season-banner card art AND the full-height profile hero background, and a size tuned to look
        // right on one badly breaks on the other (the hero's much taller box left everything below the
        // top strip solid black, since the fade radius ran out well short of the bottom).
        return "radial-gradient(farthest-corner at {$seasonX}% {$seasonY}%,{$seasonHex}3d,transparent 55%),"
            ."radial-gradient(farthest-corner at 16% 0%,{$glow},transparent 58%),"
            ."radial-gradient(farthest-corner at 88% 100%,{$hex}66,transparent 62%),"
            .'linear-gradient(180deg,#17131a,#0b0b0c)';
    }

    /** $meta carries the per-call bits that don't fit as positional params without tripping the
     * too-many-parameters check: 'rarity' (required), 'event', and — for cosmetics that render crest/glyph
     * art (currently just banners) — 'icon'/'accent_hex'. Titles just omit icon/accent_hex. */
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

    private function startNextSeason(LeaderboardSeason $season): LeaderboardSeason
    {
        $season->update(['is_active' => false]);

        $nextNumber = $season->season_number + 1;

        return LeaderboardSeason::create([
            'season_number' => $nextNumber,
            'name' => "Season {$nextNumber}",
            // Real calendar month, not a rolling 30 days — see LeaderboardService::currentSeason(). This
            // command runs daily, so it naturally rolls over right at the start of the next month.
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->endOfMonth(),
            'is_active' => true,
        ]);
    }
}
