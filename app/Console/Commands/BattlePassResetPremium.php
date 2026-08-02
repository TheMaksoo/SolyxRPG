<?php

namespace App\Console\Commands;

use App\Models\BattlePass;
use App\Models\GemLedger;
use App\Services\BattlePassService;
use Illuminate\Console\Command;

class BattlePassResetPremium extends Command
{
    protected $signature = 'battlepass:reset-premium {--force : Skip confirmation prompt}';

    protected $description = 'Resets premium status to false for all battle passes where the user has not purchased premium this season via gem ledger tracking. One-time fix for season rollover issues.';

    public function handle(): int
    {
        $currentSeason = BattlePassService::SEASON;

        // Find all battle passes with premium = true for current season
        $premiumPasses = BattlePass::where('season', $currentSeason)
            ->where('premium', true)
            ->get();

        if ($premiumPasses->isEmpty()) {
            $this->info('No premium battle passes found for current season.');
            return self::SUCCESS;
        }

        $this->info("Found {$premiumPasses->count()} premium battle pass(es) for season '{$currentSeason}'.");
        $this->info('Checking which users actually purchased premium this season...');

        $toReset = [];
        foreach ($premiumPasses as $pass) {
            $character = $pass->character;
            if (!$character || !$character->user) {
                continue;
            }

            // Check if user has a gem ledger entry for purchasing premium this season
            $hasPurchased = GemLedger::where('user_id', $character->user_id)
                ->where('reason', 'battlepass_premium_unlock')
                ->where('character_id', $character->id)
                ->exists();

            if (!$hasPurchased) {
                $toReset[] = [
                    'pass' => $pass,
                    'character_name' => $character->name,
                    'user_email' => $character->user->email,
                ];
            }
        }

        if (empty($toReset)) {
            $this->info('All premium passes have valid purchase records. Nothing to reset.');
            return self::SUCCESS;
        }

        $this->warn(count($toReset).' battle pass(es) have premium status WITHOUT purchase records:');
        foreach ($toReset as $item) {
            $this->line("  - Character: {$item['character_name']} (User: {$item['user_email']})");
        }

        if (!$this->option('force')) {
            if (!$this->confirm('Reset premium status to false for these battle passes?')) {
                $this->info('Reset cancelled.');
                return self::FAILURE;
            }
        }

        $resetCount = 0;
        foreach ($toReset as $item) {
            $item['pass']->update(['premium' => false]);
            $resetCount++;
        }

        $this->info("Successfully reset {$resetCount} battle pass(es) to free status.");

        return self::SUCCESS;
    }
}
