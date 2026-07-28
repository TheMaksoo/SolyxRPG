<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_hall_of_fame', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('leaderboard_seasons')->cascadeOnDelete();
            $table->unsignedInteger('rank');
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            // Frozen at archive time — a season champion's name should stay in the Hall of Fame
            // even if the character is later renamed or deleted.
            $table->string('character_name');
            $table->bigInteger('value');
            $table->string('reward_summary');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['season_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_hall_of_fame');
    }
};
