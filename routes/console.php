<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ends the current PvP season on the 1st of each month: grants season-exclusive title cosmetics to
// Platinum+ finishers, then soft-resets ratings toward the 1000 baseline (see PvpSeasonReset).
Schedule::command('pvp:season-reset')->monthlyOn(1, '00:05');

// Purges finished battles/dungeon runs/crafting jobs, stale party invites, old dismissed mail, and
// resolved support tickets/audit logs past their retention window (see CleanupStaleData for the
// per-table reasoning). Runs daily in the small hours since it's a maintenance job, not a player-facing
// action, and chunked deletes still touch several tables worth doing off-peak.
Schedule::command('cleanup:stale-data')->dailyAt('03:15');

// Backstop matchmaking pass over the PvP queue — the queue/join and queue/status endpoints already
// attempt to pair a waiting player on every call (that's what makes matching feel instant), this just
// catches the case where both players' clients stopped polling before either attempt could land.
Schedule::command('pvp:matchmaking-sweep')->everyMinute();

// "If no actions have been taken for an hour the user that has its turn forfeits automatically" — sweeps
// active live PvP matches and auto-forfeits whoever's turn it is once last_action_at is over an hour stale.
Schedule::command('pvp:forfeit-afk')->everyFiveMinutes();

// Returns escrowed items to sellers whose marketplace listings ran out the clock unsold — the
// buy/browse endpoints already expire past-due listings inline, this is just the backstop for
// listings nobody looked at again before they expired.
Schedule::command('market:expire-listings')->everyFifteenMinutes();
