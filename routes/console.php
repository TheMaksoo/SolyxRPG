<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ends the current PvP season on the 1st of each month: grants season-exclusive title cosmetics to
// Platinum+ finishers, then soft-resets ratings toward the 1000 baseline (see PvpSeasonReset).
Schedule::command('pvp:season-reset')->monthlyOn(1, '00:00')->timezone('Europe/Amsterdam');

// Ends the current Battle Pass season on the 1st of each month: distributes all unclaimed rewards,
// sends season-end summary mail, resets progress (tier/xp/claimed tiers), and clears premium status
// so players need to purchase it again for the new season (see BattlePassSeasonRollover).
Schedule::command('battlepass:season-rollover --force')->monthlyOn(1, '00:00')->timezone('Europe/Amsterdam');

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

// Backstop for referral rewards — ReferralController::index() already grants on page load, this
// catches referrers who never check back so a milestone reward doesn't sit ungranted indefinitely.
Schedule::command('referrals:check-milestones')->hourly();

// Records a daily (and, on Mondays, weekly) baseline+rank snapshot for every tracked leaderboard
// category — this is what makes the Leaderboard's Weekly/Daily range tabs and Δ rank-change column
// real rather than fake, without needing to load the whole characters table on every live request.
Schedule::command('leaderboard:snapshot-daily')->dailyAt('00:10');

// Ends the active leaderboard season once its end date has passed: pays out rewards ranked by Power,
// archives the top 10 into the Hall of Fame, and starts the next season. A no-op most days (see
// LeaderboardSeasonRollover) — scheduled daily so a season never runs more than a day past its end.
Schedule::command('leaderboard:season-rollover')->dailyAt('00:00')->timezone('Europe/Amsterdam');

// BetterStack heartbeat monitor for the Pusher/websocket layer — only actually pings BetterStack when
// Pusher is reachable (see PingWebsocketHeartbeat), so a missed heartbeat means real degradation, not
// just a quiet server. Set the BetterStack monitor's expected period to match this (5 min + a couple
// minutes' grace) so a single slow cron tick doesn't false-alarm.
Schedule::command('websocket:heartbeat')->everyFiveMinutes();
