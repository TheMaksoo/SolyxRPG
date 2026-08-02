<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class GmArtisanController extends Controller
{
    /**
     * List available artisan commands that can be run from the GM panel.
     */
    public function index()
    {
        return response()->json([
            'commands' => [
                [
                    'key' => 'leaderboard:distribute-rewards',
                    'label' => 'Distribute Leaderboard Rewards',
                    'description' => 'Retroactively distributes season-end rewards (gold, gems, titles, banners) for a specific season.',
                    'requires_season' => true,
                    'danger_level' => 'high',
                ],
                [
                    'key' => 'leaderboard:season-rollover',
                    'label' => 'Leaderboard Season Rollover',
                    'description' => 'Ends the current leaderboard season, distributes rewards, and starts a new season.',
                    'requires_season' => false,
                    'danger_level' => 'critical',
                ],
                [
                    'key' => 'leaderboard:snapshot-daily',
                    'label' => 'Take Leaderboard Snapshot',
                    'description' => 'Captures daily snapshot of all leaderboard categories for historical tracking.',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
                [
                    'key' => 'battlepass:season-rollover',
                    'label' => 'Battle Pass Season Rollover',
                    'description' => 'Ends the current battle pass season and creates the next one.',
                    'requires_season' => false,
                    'danger_level' => 'critical',
                ],
                [
                    'key' => 'battlepass:reset',
                    'label' => 'Reset Battle Pass',
                    'description' => 'Resets battle pass progress for all players (usually for testing).',
                    'requires_season' => false,
                    'danger_level' => 'critical',
                ],
                [
                    'key' => 'pvp:season-reset',
                    'label' => 'PvP Season Reset',
                    'description' => 'Resets PvP trophies and ratings for all characters.',
                    'requires_season' => false,
                    'danger_level' => 'critical',
                ],
                [
                    'key' => 'pvp:matchmaking-sweep',
                    'label' => 'PvP Matchmaking Sweep',
                    'description' => 'Processes pending PvP matches (runs automatically via cron).',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
                [
                    'key' => 'pvp:forfeit-afk-matches',
                    'label' => 'Forfeit AFK PvP Matches',
                    'description' => 'Auto-forfeits PvP matches where players haven\'t responded.',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
                [
                    'key' => 'referrals:check-milestones',
                    'label' => 'Check Referral Milestones',
                    'description' => 'Processes pending referral milestone rewards.',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
                [
                    'key' => 'market:expire-listings',
                    'label' => 'Expire Market Listings',
                    'description' => 'Expires old marketplace listings and returns items to sellers.',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
                [
                    'key' => 'cleanup:stale-data',
                    'label' => 'Cleanup Stale Data',
                    'description' => 'Removes old error logs, expired sessions, and other stale data.',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
            ],
        ]);
    }

    /**
     * Execute an artisan command with safety checks and logging.
     */
    public function execute(Request $request)
    {
        $validated = $request->validate([
            'command' => 'required|string',
            'season_number' => 'nullable|integer|min:1',
            'force' => 'boolean',
        ]);

        $command = $validated['command'];
        $seasonNumber = $validated['season_number'] ?? null;
        $force = $validated['force'] ?? false;

        // Validate command is in allowed list
        $allowedCommands = [
            'leaderboard:distribute-rewards',
            'leaderboard:season-rollover',
            'leaderboard:snapshot-daily',
            'battlepass:season-rollover',
            'battlepass:reset',
            'pvp:season-reset',
            'pvp:matchmaking-sweep',
            'pvp:forfeit-afk-matches',
            'referrals:check-milestones',
            'market:expire-listings',
            'cleanup:stale-data',
        ];

        if (!in_array($command, $allowedCommands)) {
            return response()->json(['error' => 'Command not allowed'], 403);
        }

        // Build command with arguments
        $commandArgs = [];
        
        if ($command === 'leaderboard:distribute-rewards' && $seasonNumber) {
            $commandArgs['season_number'] = $seasonNumber;
        }
        
        if ($force) {
            $commandArgs['--force'] = true;
        }

        try {
            // Log the command execution
            Log::info("GM Artisan Command Executed", [
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'command' => $command,
                'arguments' => $commandArgs,
                'ip' => $request->ip(),
            ]);

            // Execute the command
            $exitCode = Artisan::call($command, $commandArgs);
            $output = Artisan::output();

            return response()->json([
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $output,
                'message' => $exitCode === 0 
                    ? 'Command executed successfully' 
                    : 'Command failed with exit code ' . $exitCode,
            ]);
        } catch (\Exception $e) {
            Log::error("GM Artisan Command Failed", [
                'user_id' => $request->user()->id,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
