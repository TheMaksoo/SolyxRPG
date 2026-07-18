<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('power')->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->timestamps();

            $table->index('power');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_cache');
    }
};
