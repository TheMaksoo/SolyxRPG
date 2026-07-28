<?php

namespace App\Console\Commands;

use App\Services\LeaderboardService;
use Illuminate\Console\Command;

class LeaderboardSnapshotDaily extends Command
{
    protected $signature = 'leaderboard:snapshot-daily';

    protected $description = 'Records a daily (and, on Mondays, weekly) baseline+rank snapshot for every tracked leaderboard category, powering the Weekly/Daily range tabs and the Δ rank-change column.';

    public function handle(LeaderboardService $leaderboard): int
    {
        $leaderboard->snapshotAllCategories('daily', now()->toDateString());
        $this->info('Daily leaderboard snapshot recorded.');

        if (now()->isMonday()) {
            $leaderboard->snapshotAllCategories('weekly', now()->startOfWeek()->toDateString());
            $this->info('Weekly leaderboard snapshot recorded.');
        }

        return self::SUCCESS;
    }
}
