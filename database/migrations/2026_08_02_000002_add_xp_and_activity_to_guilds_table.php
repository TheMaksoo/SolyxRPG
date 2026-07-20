<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->unsignedInteger('xp')->default(0)->after('xp_perk');
            $table->timestamp('last_activity_at')->nullable()->after('war_status');
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn(['xp', 'last_activity_at']);
        });
    }
};
