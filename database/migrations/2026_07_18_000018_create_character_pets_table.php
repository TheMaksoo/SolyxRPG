<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('level')->default(1);
            $table->boolean('active')->default(false);
            $table->timestamps();

            $table->unique(['character_id', 'pet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_pets');
    }
};
