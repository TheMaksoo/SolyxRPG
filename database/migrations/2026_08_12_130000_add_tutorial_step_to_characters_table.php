<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Replaces TutorialOverlay's old localStorage-keyed-by-character-id tracking, which raced against
     * character data loading (the overlay could read before the real id was known, then every dismiss
     * wrote somewhere that read could never see again — see the component's own history). Tracking
     * progress as a real column on the character means it arrives with every other character field,
     * with nothing client-side left to get out of sync. */
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedTinyInteger('tutorial_step')->default(0)->after('tutorial_seen');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('tutorial_step');
        });
    }
};
