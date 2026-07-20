<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->unsignedInteger('guild_war_points')->default(0)->after('bank_gems');
            $table->timestamp('guild_war_points_reset_at')->nullable()->after('guild_war_points');
        });

        Schema::create('guild_war_battles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_a_id')->constrained('guilds')->cascadeOnDelete();
            $table->foreignId('guild_b_id')->constrained('guilds')->cascadeOnDelete();
            $table->unsignedInteger('guild_a_power')->default(0);
            $table->unsignedInteger('guild_b_power')->default(0);
            $table->foreignId('winner_guild_id')->nullable()->constrained('guilds')->nullOnDelete();
            $table->date('scheduled_for');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_war_battles');

        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn(['guild_war_points', 'guild_war_points_reset_at']);
        });
    }
};
