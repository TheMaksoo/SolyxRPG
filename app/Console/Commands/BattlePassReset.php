<?php

namespace App\Console\Commands;

use App\Models\BattlePass;
use Illuminate\Console\Command;

class BattlePassReset extends Command
{
    protected $signature = 'battlepass:reset {--force : Skip confirmation prompt}';

    protected $description = 'Resets all battle passes back to tier 1 (tier 0 with 0 XP). Use this as a one-time fix or when starting a new season.';

    public function handle(): int
    {
        $count = BattlePass::count();

        if ($count === 0) {
            $this->info('No battle passes found to reset.');
            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            if (!$this->confirm("This will reset {$count} battle pass(es) to tier 0 (XP: 0). Continue?")) {
                $this->info('Reset cancelled.');
                return self::FAILURE;
            }
        }

        $updated = BattlePass::query()->update([
            'tier' => 0,
            'xp' => 0,
            'claimed_free_tiers' => null,
            'claimed_premium_tiers' => null,
        ]);

        $this->info("Successfully reset {$updated} battle pass(es) to tier 0.");

        return self::SUCCESS;
    }
}
