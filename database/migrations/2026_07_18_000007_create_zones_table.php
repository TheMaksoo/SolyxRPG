<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('glyph', 8)->default('');
            $table->enum('danger', ['safe', 'medium', 'high', 'deadly'])->default('safe');
            $table->unsignedInteger('min_level')->default(1);
            $table->boolean('locked')->default(false);
            $table->boolean('enabled')->default(true);
            $table->boolean('tester_only')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
