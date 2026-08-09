<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tamed_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monster_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('glyph');
            $table->boolean('is_elite')->default(false);
            $table->unsignedInteger('base_hp');
            $table->unsignedInteger('base_atk');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedInteger('current_hp');
            $table->timestamp('last_regen_at')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tamed_companions');
    }
};
