<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('value');
            $table->string('accent_hex')->nullable()->after('icon');
            // Procedurally-granted, per-character cosmetics (season leaderboard placements, PvP season
            // rewards) rather than fixed catalog items — CosmeticController::index() hides these from
            // characters who haven't unlocked them instead of listing every combination as browsable.
            $table->boolean('is_dynamic')->default(false)->after('enabled');
        });

        // Backfill: cosmetics already granted by LeaderboardSeasonRollover (category 'season') or
        // PvpSeasonReset (unlock_event 'pvp_season_*') existed before this column did — Cosmetic::
        // firstOrCreate() never updates an already-existing row, so future rollovers won't retroactively
        // flip these. Mark them dynamic now so the new visibility rule applies to rewards already granted.
        DB::table('cosmetics')
            ->where('category', 'season')
            ->orWhere('unlock_event', 'like', 'pvp_season_%')
            ->update(['is_dynamic' => true]);
    }

    public function down(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->dropColumn(['icon', 'accent_hex', 'is_dynamic']);
        });
    }
};
