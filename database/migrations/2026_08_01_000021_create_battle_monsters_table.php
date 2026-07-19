<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_monsters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monster_id')->constrained();
            $table->unsignedInteger('hp');
            $table->unsignedInteger('hp_max');
            $table->unsignedTinyInteger('slot');
            $table->timestamp('defeated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_monsters');
    }
};
