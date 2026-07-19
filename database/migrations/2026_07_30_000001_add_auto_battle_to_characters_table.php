<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('auto_battle_expires_at')->nullable()->after('current_zone_id');
            $table->timestamp('auto_battle_last_tick_at')->nullable()->after('auto_battle_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['auto_battle_expires_at', 'auto_battle_last_tick_at']);
        });
    }
};
