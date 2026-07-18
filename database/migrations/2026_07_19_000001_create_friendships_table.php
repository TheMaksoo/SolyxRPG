<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('addressee_id')->constrained('characters')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamps();

            $table->unique(['requester_id', 'addressee_id']);
        });

        Schema::create('character_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('favorite_character_id')->constrained('characters')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['character_id', 'favorite_character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_favorites');
        Schema::dropIfExists('friendships');
    }
};
