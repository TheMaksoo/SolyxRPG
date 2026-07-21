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
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 10)->nullable()->unique()->after('id');
            $table->foreignId('referred_by_user_id')->nullable()->after('referral_code')
                ->constrained('users')->nullOnDelete();
            $table->unsignedInteger('referral_rewards_claimed')->default(0)->after('referred_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn(['referral_code', 'referral_rewards_claimed']);
        });
    }
};
