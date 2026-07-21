<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lightweight matchmaking pool — not a permanent record. Rows get deleted the moment a match is
        // found or the player leaves the queue, so this table should normally be near-empty.
        Schema::create('pvp_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rating');
            $table->timestamp('queued_at');
        });

        // A real-time, turn-based PvP match between two actual players. Distinct from pvp_matches, which
        // remains the permanent historical record (one row created once this finishes, same as before).
        Schema::create('pvp_live_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_a_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('character_b_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('turn_character_id')->constrained('characters')->cascadeOnDelete();
            $table->json('state_json');
            $table->json('log_json');
            $table->enum('status', ['active', 'finished', 'forfeited'])->default('active');
            $table->foreignId('winner_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamp('last_action_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'last_action_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pvp_live_matches');
        Schema::dropIfExists('pvp_queue');
    }
};
