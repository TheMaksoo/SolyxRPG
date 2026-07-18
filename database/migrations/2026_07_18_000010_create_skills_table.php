<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('branch');
            $table->string('key')->unique();
            $table->string('name');
            $table->string('glyph', 8)->default('');
            $table->text('description')->nullable();
            $table->unsignedInteger('tier')->default(1);
            $table->unsignedInteger('level_req')->default(1);
            $table->unsignedInteger('mp_cost')->default(0);
            $table->json('effect_json')->nullable();
            $table->string('class_scope')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
