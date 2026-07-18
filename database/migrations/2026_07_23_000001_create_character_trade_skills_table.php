<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Gathering/trade skills (Mining, Woodchopping, Smelting, Crafting) — separate from the combat skill tree and from Character.profession (the Lv.40 class specialization). */
    public function up(): void
    {
        Schema::create('character_trade_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('skill_key');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('xp')->default(0);
            $table->timestamp('last_action_at')->nullable();
            $table->unique(['character_id', 'skill_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_trade_skills');
    }
};
