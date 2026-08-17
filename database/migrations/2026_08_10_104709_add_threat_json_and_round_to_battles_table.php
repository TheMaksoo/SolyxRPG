<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** `threat_json` backs the real per-enemy threat table (see ThreatService) that now decides who an
     * enemy's attack lands on, replacing the flat %-per-pet damage-share/aggro-roll model. `round`
     * tracks the real per-unit turn queue's round counter across the whole fight (not just this
     * request) — used for the 30-round stalemate guard. */
    public function up(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->json('threat_json')->nullable()->after('skill_cooldowns_json');
            $table->unsignedInteger('round')->default(1)->after('threat_json');
        });
    }

    public function down(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->dropColumn(['threat_json', 'round']);
        });
    }
};
