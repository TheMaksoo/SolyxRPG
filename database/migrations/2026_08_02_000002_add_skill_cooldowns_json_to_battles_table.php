<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Per-battle "rounds remaining" map, keyed by skill id — mirrors the existing monster_cooldowns_json
     * pattern. Turn-based skill cooldowns only make sense scoped to a single battle (a battle is one
     * synchronous round-loop), so this lives here rather than on the character's persistent skill row. */
    public function up(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->json('skill_cooldowns_json')->nullable()->after('monster_cooldowns_json');
        });
    }

    public function down(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->dropColumn('skill_cooldowns_json');
        });
    }
};
