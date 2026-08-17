<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_status_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_id')->constrained()->cascadeOnDelete();
            // 'player' (target_id null), 'companion' (target_id = battle_companions.id), or 'monster'
            // (target_id = battle_monsters.id, or 0 for the primary monster slot, which has no row of
            // its own since it lives directly on the battles table).
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('effect_key');
            $table->decimal('magnitude', 8, 2);
            $table->unsignedTinyInteger('remaining_rounds');
            $table->string('source_label')->nullable();
            $table->timestamps();

            $table->index(['battle_id', 'target_type', 'target_id', 'effect_key'], 'battle_status_effects_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_status_effects');
    }
};
