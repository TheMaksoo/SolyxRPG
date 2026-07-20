<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->unsignedTinyInteger('gold_find_upgrade_tier')->default(0)->after('xp_perk');
            $table->unsignedTinyInteger('xp_upgrade_tier')->default(0)->after('gold_find_upgrade_tier');
            $table->unsignedTinyInteger('luck_upgrade_tier')->default(0)->after('xp_upgrade_tier');
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn(['gold_find_upgrade_tier', 'xp_upgrade_tier', 'luck_upgrade_tier']);
        });
    }
};
