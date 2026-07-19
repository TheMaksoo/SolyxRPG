<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battle_passes', function (Blueprint $table) {
            $table->json('claimed_free_tiers')->nullable()->after('premium');
            $table->json('claimed_premium_tiers')->nullable()->after('claimed_free_tiers');
        });
    }

    public function down(): void
    {
        Schema::table('battle_passes', function (Blueprint $table) {
            $table->dropColumn(['claimed_free_tiers', 'claimed_premium_tiers']);
        });
    }
};
