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
