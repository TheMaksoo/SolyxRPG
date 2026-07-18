<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('damage')->default(0);
            $table->unsignedInteger('armor')->default(0);
            $table->unsignedInteger('hp')->default(0);
            $table->unsignedInteger('mp')->default(0);
            $table->unsignedInteger('crit')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_attributes');
    }
};
