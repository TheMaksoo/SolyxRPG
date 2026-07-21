<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('pvp_wins_today')->default(0)->after('last_daily_reward_at');
            $table->date('pvp_wins_today_date')->nullable()->after('pvp_wins_today');
            $table->timestamp('pvp_10_wins_reward_at')->nullable()->after('pvp_wins_today_date');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['pvp_wins_today', 'pvp_wins_today_date', 'pvp_10_wins_reward_at']);
        });
    }
};
