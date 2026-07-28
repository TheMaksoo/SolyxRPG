<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->enum('period', ['daily', 'weekly', 'season']);
            $table->string('period_key');
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('baseline_value')->default(0);
            $table->unsignedInteger('rank_at_boundary')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['category', 'period', 'period_key', 'character_id'], 'leaderboard_snapshots_unique');
            $table->index(['category', 'period', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_snapshots');
    }
};
