<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('glyph', 8)->default('');
            $table->text('description')->nullable();
            $table->json('requirement_json');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('character_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->timestamp('earned_at')->useCurrent();

            $table->unique(['character_id', 'achievement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_achievements');
        Schema::dropIfExists('achievements');
    }
};
