<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            // Set instead of archiving immediately when hp hits 0 in battle — gives the player up to
            // companion_revive_max_attempts (GameConfig, default 2) tries with a Pet Revive Potion from
            // the Companions page before it's archived for good. See CombatService's down-companion path
            // and TamedCompanionController::revive().
            $table->boolean('is_downed')->default(false)->after('current_hp');
            $table->unsignedTinyInteger('revive_attempts_used')->default(0)->after('is_downed');
        });
    }

    public function down(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            $table->dropColumn(['is_downed', 'revive_attempts_used']);
        });
    }
};
