<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pvp_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rating')->default(1000);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('win_streak')->default(0);
            $table->timestamps();
        });

        Schema::create('pvp_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opponent_id')->constrained('characters')->cascadeOnDelete();
            $table->enum('result', ['win', 'loss']);
            $table->integer('rating_delta');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pvp_matches');
        Schema::dropIfExists('pvp_records');
    }
};
