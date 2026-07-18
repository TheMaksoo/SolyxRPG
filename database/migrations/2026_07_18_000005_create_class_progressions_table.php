<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_progressions', function (Blueprint $table) {
            $table->id();
            $table->enum('base_class', ['warrior', 'mage', 'rogue', 'ranger']);
            $table->enum('tier', ['t20', 't40', 't60']);
            $table->string('key');
            $table->string('name');
            $table->string('glyph', 8)->default('');
            $table->text('description')->nullable();
            $table->unsignedInteger('level_cap');
            $table->timestamps();

            $table->unique(['base_class', 'tier', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_progressions');
    }
};
