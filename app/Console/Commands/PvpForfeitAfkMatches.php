<?php

namespace App\Console\Commands;

use App\Models\PvpLiveMatch;
use App\Services\PvpLiveCombatService;
use Illuminate\Console\Command;

/** "If no actions have been taken for an hour the user that has its turn forfeits automatically" — sweeps
 * active live PvP matches for one where last_action_at (reset on every action by either side, NOT match
 * creation time) is over an hour stale, and auto-forfeits whoever's turn it currently is. Registered in
 * routes/console.php every 5 minutes — frequent enough that nobody waits much past the hour mark, without
 * hammering the DB. */
class PvpForfeitAfkMatches extends Command
{
    protected $signature = 'pvp:forfeit-afk';

    protected $description = 'Auto-forfeits live PvP matches where the player on the clock has gone quiet for over an hour.';

    public function handle(PvpLiveCombatService $combat): int
    {
        $stale = PvpLiveMatch::where('status', 'active')
            ->where('last_action_at', '<', now()->subHour())
            ->get();

        foreach ($stale as $match) {
            $afkCharacterId = $match->turn_character_id;
            $winnerCharacterId = $match->opponentIdFor($afkCharacterId);
            if (! $winnerCharacterId) {
                continue;
            }

            $log = $match->log_json ?? [];
            $log[] = 'Match auto-forfeited — no action taken for over an hour.';

            $match->status = 'forfeited';
            $match->winner_character_id = $winnerCharacterId;
            $match->log_json = $log;
            $match->save();

            $reward = $combat->resolveMatchWin($match, $winnerCharacterId, $afkCharacterId, $log);
            $state = $match->state_json;
            $state['reward'] = $reward;
            $match->state_json = $state;
            $match->save();
        }

        $this->info(count($stale).' stale PvP match(es) auto-forfeited.');

        return self::SUCCESS;
    }
}
