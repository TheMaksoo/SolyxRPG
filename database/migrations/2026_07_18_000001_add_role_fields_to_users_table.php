<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['player', 'tester', 'gm', 'owner'])->default('player')->after('password');
            $table->boolean('is_tester')->default(false)->after('role');
            $table->boolean('ads_removed')->default(false)->after('is_tester');
            $table->enum('vip_tier', ['none', 'bronze', 'gold', 'diamond'])->default('none')->after('ads_removed');
            $table->timestamp('vip_expires_at')->nullable()->after('vip_tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_tester', 'ads_removed', 'vip_tier', 'vip_expires_at']);
        });
    }
};
