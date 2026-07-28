<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Anti-cheat budget for the client's tick-driven regen sync (see Character::registerSyncCall) —
            // a rolling per-day counter, reset on the fly whenever it's stale, rather than a scheduled job.
            $table->unsignedInteger('sync_calls_today')->default(0)->after('last_energy_regen_at');
            $table->timestamp('sync_calls_reset_at')->nullable()->after('sync_calls_today');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['sync_calls_today', 'sync_calls_reset_at']);
        });
    }
};
