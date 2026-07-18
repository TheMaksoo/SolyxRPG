<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_passes', function (Blueprint $table) {
            $table->id();
            $table->string('season');
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tier')->default(0);
            $table->unsignedInteger('xp')->default(0);
            $table->boolean('premium')->default(false);
            $table->timestamps();

            $table->unique(['season', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_passes');
    }
};
