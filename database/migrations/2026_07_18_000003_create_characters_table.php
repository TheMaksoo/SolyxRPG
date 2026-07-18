<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('base_class', ['warrior', 'mage', 'rogue', 'ranger']);
            $table->string('spec_class')->nullable();
            $table->string('profession')->nullable();
            $table->string('ascension')->nullable();
            $table->string('avatar')->default('');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedBigInteger('gold')->default(0);
            $table->unsignedBigInteger('gems')->default(0);
            $table->integer('hp')->default(100);
            $table->integer('hp_max')->default(100);
            $table->integer('mana')->default(50);
            $table->integer('mana_max')->default(50);
            $table->integer('base_atk')->default(10);
            $table->integer('base_def')->default(5);
            $table->unsignedInteger('skill_points')->default(0);
            $table->unsignedInteger('attribute_points')->default(0);
            $table->foreignId('current_zone_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
