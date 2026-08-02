<?php

namespace App\Console\Commands;

use App\Models\BattlePass;
use App\Models\Character;
use App\Models\Mail;
use App\Services\BattlePassService;
use Illuminate\Console\Command;

class BattlePassDistributeEndRewards extends Command
{
    protected $signature = 'battlepass:distribute-end-rewards {season?} {--force : Skip confirmation prompt}';

    protected $description = 'Distributes end-of-season rewards for all characters who reached tiers but haven\'t claimed them yet. Can specify a season or defaults to current season.';

    public function __construct(private BattlePassService $battlePass)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $season = $this->argument('season') ?? BattlePassService::SEASON;

        $this->info("Processing end-of-season rewards for season '{$season}'...");

        $passes = BattlePass::where('season', $season)
            ->where('tier', '>', 0)
            ->with('character.user')
            ->get();

        if ($passes->isEmpty()) {
            $this->info('No battle passes found for this season.');
            return self::SUCCESS;
        }

        $this->info("Found {$passes->count()} battle pass(es) with progress.");

        // Analyze what needs to be distributed
        $needsRewards = [];
        foreach ($passes as $pass) {
            $character = $pass->character;
            if (!$character || !$character->user) {
                continue;
            }

            $claimedFree = $pass->claimed_free_tiers ?? [];
            $claimedPremium = $pass->claimed_premium_tiers ?? [];

            $unclaimedFree = [];
            for ($tier = 1; $tier <= $pass->tier; $tier++) {
                if (!in_array($tier, $claimedFree, true)) {
                    $unclaimedFree[] = $tier;
                }
            }

            $unclaimedPremium = [];
            if ($pass->premium) {
                for ($tier = 1; $tier <= $pass->tier; $tier++) {
                    if (!in_array($tier, $claimedPremium, true)) {
                        $unclaimedPremium[] = $tier;
                    }
                }
            }

            if (!empty($unclaimedFree) || !empty($unclaimedPremium)) {
                $needsRewards[] = [
                    'character' => $character,
                    'pass' => $pass,
                    'unclaimed_free' => count($unclaimedFree),
                    'unclaimed_premium' => count($unclaimedPremium),
                ];
            }
        }

        if (empty($needsRewards)) {
            $this->info('All rewards have already been claimed. Nothing to distribute.');
            return self::SUCCESS;
        }

        $this->warn(count($needsRewards).' character(s) have unclaimed rewards:');
        foreach (array_slice($needsRewards, 0, 10) as $item) {
            $this->line(sprintf(
                "  - %s: %d free tiers, %d premium tiers (max tier: %d)",
                $item['character']->name,
                $item['unclaimed_free'],
                $item['unclaimed_premium'],
                $item['pass']->tier
            ));
        }
        if (count($needsRewards) > 10) {
            $this->line('  ... and '.(count($needsRewards) - 10).' more');
        }

        if (!$this->option('force')) {
            if (!$this->confirm('Distribute all unclaimed rewards to these characters?')) {
                $this->info('Distribution cancelled.');
                return self::FAILURE;
            }
        }

        $this->info('Distributing rewards...');
        $bar = $this->output->createProgressBar(count($needsRewards));
        $bar->start();

        $totalDistributed = 0;
        $totalGold = 0;
        $totalGems = 0;

        foreach ($needsRewards as $item) {
            $character = $item['character'];
            $totals = $this->battlePass->claimAll($character);

            if ($totals['tiers_claimed'] > 0) {
                // Send an in-game mail notification
                Mail::create([
                    'recipient_user_id' => $character->user_id,
                    'subject' => "Season {$season} Battle Pass Rewards",
                    'body' => sprintf(
                        "Your end-of-season rewards have been distributed! You received: %s gold, %s gems, and %d item(s) from %d tier(s).",
                        number_format($totals['gold']),
                        number_format($totals['gems']),
                        count($totals['items']),
                        $totals['tiers_claimed']
                    ),
                    'created_at' => now(),
                ]);

                $totalDistributed++;
                $totalGold += $totals['gold'];
                $totalGems += $totals['gems'];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Successfully distributed rewards to {$totalDistributed} character(s).");
        $this->info("Total distributed: ".number_format($totalGold)." gold, ".number_format($totalGems)." gems");

        return self::SUCCESS;
    }
}
