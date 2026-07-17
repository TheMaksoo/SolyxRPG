<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_entries', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('glyph', 8);
            $table->string('name');
            $table->string('sub');
            $table->enum('rarity', ['Common', 'Rare', 'Epic', 'Legendary', 'Mythic'])->default('Common');
            $table->text('description');
            $table->json('stats')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->boolean('tester_only')->default(false);
            $table->timestamps();

            $table->index(['category', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_entries');
    }
};
