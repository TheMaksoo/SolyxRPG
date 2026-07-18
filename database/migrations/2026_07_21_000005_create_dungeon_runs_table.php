<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeon_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dungeon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('battle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('stage')->default(1);
            $table->unsignedInteger('total_stages')->default(1);
            $table->enum('status', ['active', 'completed', 'abandoned'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dungeon_runs');
    }
};
