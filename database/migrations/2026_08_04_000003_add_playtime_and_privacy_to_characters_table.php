<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('playtime_seconds')->default(0)->after('playstyle_tags');
            // Read-and-bumped on every authenticated API request (see TrackCharacterActivity middleware)
            // to accumulate playtime_seconds by the elapsed time since the last request, capped so a
            // stale/idle tab reconnecting after hours doesn't inflate playtime in one jump.
            $table->timestamp('last_active_at')->nullable()->after('playtime_seconds');
            // Per-field public-profile visibility — see CharacterController::publicProfile() for the
            // gating this drives. Defaults applied in the model accessor, not here, so existing rows
            // (this column starts null) behave exactly like the previous always-public behavior.
            $table->json('privacy_settings')->nullable()->after('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['playtime_seconds', 'last_active_at', 'privacy_settings']);
        });
    }
};
