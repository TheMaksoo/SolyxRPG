<?php

namespace App\Console\Commands;

use App\Models\BattlePass;
use App\Models\Mail;
use App\Services\BattlePassService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BattlePassSeasonRollover extends Command
{
    protected $signature = 'battlepass:season-rollover {--force : Skip confirmation prompt}';

    protected $description = 'Ends the current battle pass season: distributes all unclaimed rewards, resets progress for the next season, and clears premium status. Safe to schedule monthly at the start of a new month.';

    public function __construct(private BattlePassService $battlePass)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $currentSeason = BattlePassService::SEASON;

        $this->info("Current battle pass season: '{$currentSeason}'");
        $this->info('This will:');
        $this->line('  1. Distribute all unclaimed rewards to players');
        $this->line('  2. Reset all battle pass progress (tier, XP, claimed tiers)');
        $this->line('  3. Reset premium status to false for all players');
        $this->line('  4. Send summary mail notifications');

        if (!$this->option('force')) {
            if (!$this->confirm('Proceed with season rollover?')) {
                $this->info('Rollover cancelled.');
                return self::FAILURE;
            }
        }

        DB::beginTransaction();

        try {
            // Step 1: Distribute unclaimed rewards
            $this->info('Step 1/4: Distributing unclaimed rewards...');
            $distributed = $this->distributeUnclaimedRewards($currentSeason);
            $this->info("  ✓ Distributed rewards to {$distributed} character(s)");

            // Step 2: Send season-end summary notifications
            $this->info('Step 2/4: Sending season-end summaries...');
            $summariesSent = $this->sendSeasonEndSummaries($currentSeason);
            $this->info("  ✓ Sent summaries to {$summariesSent} character(s)");

            // Step 3: Reset all battle pass progress
            $this->info('Step 3/4: Resetting battle pass progress...');
            $resetCount = BattlePass::where('season', $currentSeason)->update([
                'tier' => 0,
                'xp' => 0,
                'premium' => false,
                'claimed_free_tiers' => null,
                'claimed_premium_tiers' => null,
            ]);
            $this->info("  ✓ Reset {$resetCount} battle pass(es)");

            // Step 4: Note - season constant needs to be manually updated in BattlePassService
            $this->info('Step 4/4: Season rollover complete!');
            $this->warn('⚠️  IMPORTANT: Update BattlePassService::SEASON constant to the next season name.');

            DB::commit();

            $this->info('✅ Season rollover completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Rollover failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Distributes all unclaimed rewards for characters who reached tiers.
     */
    private function distributeUnclaimedRewards(string $season): int
    {
        $passes = BattlePass::where('season', $season)
            ->where('tier', '>', 0)
            ->with('character.user')
            ->get();

        $distributed = 0;

        foreach ($passes as $pass) {
            $character = $pass->character;
            if (!$character || !$character->user) {
                continue;
            }

            $totals = $this->battlePass->claimAll($character);

            if ($totals['tiers_claimed'] > 0) {
                $distributed++;
            }
        }

        return $distributed;
    }

    /**
     * Sends season-end summary mail to all participants.
     */
    private function sendSeasonEndSummaries(string $season): int
    {
        $passes = BattlePass::where('season', $season)
            ->with('character.user')
            ->get();

        $sent = 0;

        foreach ($passes as $pass) {
            $character = $pass->character;
            if (!$character || !$character->user) {
                continue;
            }

            // Only send if they made any progress
            if ($pass->tier === 0) {
                continue;
            }

            $totalXpEarned = $this->battlePass->totalSeasonPoints($pass);
            $premiumText = $pass->premium ? ' (Premium)' : ' (Free)';

            Mail::create([
                'recipient_user_id' => $character->user_id,
                'subject' => "Season '{$season}' has ended!",
                'body' => sprintf(
                    "The Battle Pass season has concluded! Your final stats:\n\n• Max Tier Reached: %d / %d%s\n• Total Points: %s\n\nAll unclaimed rewards have been distributed to your inventory. Thank you for playing!\n\nThe new season starts now with fresh progress. Good luck!",
                    $pass->tier,
                    BattlePassService::TOTAL_TIERS,
                    $premiumText,
                    number_format($totalXpEarned)
                ),
                'created_at' => now(),
            ]);

            $sent++;
        }

        return $sent;
    }
}
