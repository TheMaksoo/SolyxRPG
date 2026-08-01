<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('referral_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('level_milestone');
            $table->timestamp('reward_granted_at')->nullable();
            $table->timestamps();
            
            // Ensure we only track each milestone once per referrer-referee pair
            $table->unique(['referrer_id', 'referee_id', 'level_milestone']);
            $table->index(['referrer_id', 'level_milestone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_milestones');
    }
};
