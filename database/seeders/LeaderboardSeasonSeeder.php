<?php

namespace Database\Seeders;

use App\Models\LeaderboardSeason;
use Illuminate\Database\Seeder;

class LeaderboardSeasonSeeder extends Seeder
{
    public function run(): void
    {
        // Initialize Season 1 if no seasons exist yet
        // This ensures the leaderboard system has a valid active season from the start
        if (LeaderboardSeason::count() === 0) {
            LeaderboardSeason::create([
                'season_number' => 1,
                'name' => 'Season 1',
                'starts_at' => now()->startOfMonth(),
                'ends_at' => now()->endOfMonth(),
                'is_active' => true,
            ]);
        }
    }
}
