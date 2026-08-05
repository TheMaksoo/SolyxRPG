<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a separate timestamp to track when the referred player (referee) received their own
     * per-milestone gem bonus — distinct from reward_granted_at, which tracks the referrer's reward.
     */
    public function up(): void
    {
        Schema::table('referral_milestones', function (Blueprint $table) {
            $table->timestamp('referee_reward_granted_at')->nullable()->after('reward_granted_at');
        });
    }

    public function down(): void
    {
        Schema::table('referral_milestones', function (Blueprint $table) {
            $table->dropColumn('referee_reward_granted_at');
        });
    }
};
