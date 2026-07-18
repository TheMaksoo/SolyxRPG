<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monsters', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('glyph', 8)->default('');
            $table->unsignedInteger('hp');
            $table->unsignedInteger('atk');
            $table->unsignedInteger('gold')->default(0);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedInteger('gems')->default(0);
            $table->boolean('is_boss')->default(false);
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->json('loot_table_json')->nullable();
            $table->unsignedInteger('min_level')->default(1);
            $table->boolean('enabled')->default(true);
            $table->boolean('tester_only')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monsters');
    }
};
