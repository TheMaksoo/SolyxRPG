<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GmArtisanController extends Controller
{
    /** Mirrors index()'s per-command danger_level metadata — kept as its own lookup so execute() can
     * gate on it without re-serializing the full command list. Every 'critical' command here is a
     * season-wide, irreversible action (rollovers/resets affecting every player), so a plain `gm`
     * account can't run one — only `owner`, same tier GmPlayerController already requires for role
     * changes. */
    private const DANGER_LEVELS = [
        'migrate' => 'low',
        'db:seed' => 'low',
        'leaderboard:distribute-rewards' => 'high',
        'leaderboard:season-rollover' => 'critical',
        'leaderboard:snapshot-daily' => 'low',
        'battlepass:season-rollover' => 'critical',
        'battlepass:reset' => 'critical',
        'pvp:season-reset' => 'critical',
        'pvp:matchmaking-sweep' => 'low',
        'pvp:forfeit-afk-matches' => 'low',
        'referrals:check-milestones' => 'low',
        'market:expire-listings' => 'low',
        'cleanup:stale-data' => 'low',
    ];

    /**
     * List available seeders in the database/seeders directory.
     */
    public function seeders()
    {
        $seederPath = database_path('seeders');
        $seederFiles = File::files($seederPath);
        
        $seeders = collect($seederFiles)
            ->filter(function ($file) {
                // Exclude DatabaseSeeder (that runs all seeders) and any temporary files
                $name = $file->getFilenameWithoutExtension();
                return $name !== 'DatabaseSeeder' 
                    && !str_ends_with($name, '_temp')
                    && str_ends_with($file->getFilename(), '.php');
            })
            ->map(function ($file) {
                $className = $file->getFilenameWithoutExtension();
                return [
                    'class' => $className,
                    'label' => $this->formatSeederLabel($className),
                ];
            })
            ->sortBy('label')
            ->values();

        return response()->json(['seeders' => $seeders]);
    }

    /**
     * Convert seeder class name to a readable label.
     */
    private function formatSeederLabel(string $className): string
    {
        // Remove "Seeder" suffix and split on capital letters
        $name = str_replace('Seeder', '', $className);
        $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
        return $name;
    }

    /**
     * List available artisan commands that can be run from the GM panel.
     */
    public function index()
    {
        return response()->json([
            'commands' => [
                [
                    'key' => 'migrate',
                    'label' => 'Run Migrations',
                    'description' => 'Runs any pending database migrations. Safe to run multiple times.',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
                [
                    'key' => 'db:seed',
                    'label' => 'Run Seeders',
                    'description' => 'Re-runs all database seeders to update game content (items, monsters, zones, etc.). Safe to run — all seeders use updateOrCreate() to avoid duplicates.',
                    'requires_season' => false,
                    'danger_level' => 'low',
                ],
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
            'seeder_class' => 'nullable|string', // For db:seed --class=SpecificSeeder
        ]);

        $command = $validated['command'];
        $seasonNumber = $validated['season_number'] ?? null;
        $force = $validated['force'] ?? false;
        $seederClass = $validated['seeder_class'] ?? null;

        // Validate command is in allowed list
        $allowedCommands = [
            'migrate',
            'db:seed',
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

        // A plain `gm` account can toggle flags, tune config, and edit content, but a 'critical'
        // artisan command (season rollovers, battle-pass reset, PvP reset) affects every player
        // irreversibly — only Owner may run one, matching the existing role-change gate.
        if ((self::DANGER_LEVELS[$command] ?? 'low') === 'critical' && $request->user()->role !== 'owner') {
            return response()->json(['error' => 'Only an Owner can run this command — it is season-critical.'], 403);
        }

        // Build command with arguments
        $commandArgs = [];
        $forceSupportedCommands = [
            'migrate',
            'db:seed',
            'leaderboard:distribute-rewards',
            'battlepass:season-rollover',
            'battlepass:reset',
        ];
        
        if ($command === 'leaderboard:distribute-rewards' && $seasonNumber) {
            $commandArgs['season_number'] = $seasonNumber;
        }
        
        // Add seeder class if specified for db:seed
        if ($command === 'db:seed' && $seederClass) {
            $commandArgs['--class'] = $seederClass;
        }
        
        if ($force && in_array($command, $forceSupportedCommands, true)) {
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

            // Every other GM action (config, flags, content, player edits) goes through AuditLog —
            // artisan runs previously only hit the app log, leaving the highest-risk action in this
            // whole console out of the same audit trail everything else uses.
            AuditLog::record($request->user()->id, 'gm.artisan.execute', 'artisan_command', null, [
                'command' => $command,
                'arguments' => $commandArgs,
                'exit_code' => $exitCode,
            ]);

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
