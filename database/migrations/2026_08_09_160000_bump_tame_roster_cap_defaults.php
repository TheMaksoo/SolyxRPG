<?php

use App\Models\GameConfig;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Rosters gained +1 slot across the board (see User::STANDARD_TAME_ROSTER_CAP) so taming a
     * better companion never forces releasing one sight-unseen first. The VIP tiers' cap can already
     * live in game_config (created on first GM Console visit via GmConfigController::index's
     * firstOrCreate) — only bump a row still sitting at its old default, so a GM who already
     * hand-tuned one of these keeps their own value. */
    private const OLD_TO_NEW = [
        'tame_roster_cap_bronze' => ['4', '5'],
        'tame_roster_cap_gold' => ['5', '6'],
        'tame_roster_cap_diamond' => ['6', '7'],
    ];

    public function up(): void
    {
        foreach (self::OLD_TO_NEW as $key => [$old, $new]) {
            GameConfig::where('key', $key)->where('value', $old)->update(['value' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::OLD_TO_NEW as $key => [$old, $new]) {
            GameConfig::where('key', $key)->where('value', $new)->update(['value' => $old]);
        }
    }
};
