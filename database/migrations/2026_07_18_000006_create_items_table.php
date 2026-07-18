<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->enum('type', ['weapon', 'armor', 'consumable', 'cosmetic', 'material']);
            $table->enum('rarity', ['common', 'rare', 'epic', 'legendary', 'mythic'])->default('common');
            $table->string('glyph', 8)->default('');
            $table->text('description')->nullable();
            $table->json('stat_json')->nullable();
            $table->unsignedInteger('price_gold')->nullable();
            $table->unsignedInteger('price_gems')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('tester_only')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
