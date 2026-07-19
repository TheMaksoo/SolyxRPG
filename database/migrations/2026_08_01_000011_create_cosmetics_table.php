<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cosmetics', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->enum('type', ['title', 'color', 'banner']);
            $table->string('name');
            $table->string('value');
            $table->unsignedInteger('cost_gems')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('character_cosmetics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cosmetic_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at')->useCurrent();

            $table->unique(['character_id', 'cosmetic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_cosmetics');
        Schema::dropIfExists('cosmetics');
    }
};
