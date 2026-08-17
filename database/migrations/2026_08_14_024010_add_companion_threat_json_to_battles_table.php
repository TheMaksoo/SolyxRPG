<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** `companion_threat_json` is the reverse of `threat_json` (see ThreatService::addRetaliationThreat) —
     * instead of tracking who an ENEMY is angriest at, it tracks who a COMPANION is angriest at, so a
     * tamed companion can pick its own attack target off its own grudges instead of blindly mirroring
     * whatever the player is currently targeting. */
    public function up(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->json('companion_threat_json')->nullable()->after('threat_json');
        });
    }

    public function down(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->dropColumn('companion_threat_json');
        });
    }
};
