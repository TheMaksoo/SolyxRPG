<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // StatsController::public() runs two range-scans on characters.updated_at
        // (players_online / players_active_hour) on every GameLayout.vue mount (every
        // authenticated page load) and on the anonymous landing page. Without this the
        // query full-scans the whole characters table on every single page view.
        Schema::table('characters', function (Blueprint $table) {
            $table->index('updated_at');
        });

        // Not a user-facing query path — these support cleanup:stale-data's chunked purge
        // of finished/old rows (see App\Console\Commands\CleanupStaleData). Without an index
        // on the columns the purge filters by, a daily cleanup run against a large table would
        // itself become a full-table-scan/lock bottleneck, which defeats the point of cleaning up.
        Schema::table('battles', function (Blueprint $table) {
            $table->index(['status', 'updated_at']);
        });

        Schema::table('dungeon_runs', function (Blueprint $table) {
            $table->index(['status', 'updated_at']);
        });

        Schema::table('crafting_jobs', function (Blueprint $table) {
            $table->index('collected_at');
        });

        Schema::table('mails', function (Blueprint $table) {
            $table->index('dismissed_at');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index(['status', 'updated_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });

        Schema::table('battles', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
        });

        Schema::table('dungeon_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
        });

        Schema::table('crafting_jobs', function (Blueprint $table) {
            $table->dropIndex(['collected_at']);
        });

        Schema::table('mails', function (Blueprint $table) {
            $table->dropIndex(['dismissed_at']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
