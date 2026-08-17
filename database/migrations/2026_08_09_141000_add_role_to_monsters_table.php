<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            // See MonsterAiService::ROLES — drives AI targeting/ability priority, not just flavor text.
            // Defaults to 'brute' (today's plain weighted-random attack behavior) so every existing
            // monster keeps acting exactly as before until a seeder backfills a more specific role.
            $table->string('role')->default('brute')->after('is_elite');
        });
    }

    public function down(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
