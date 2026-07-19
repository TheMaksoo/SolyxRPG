<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('auto_gather_skill')->nullable()->after('auto_battle_paused_at');
            $table->string('auto_gather_target')->nullable()->after('auto_gather_skill');
            $table->timestamp('auto_gather_expires_at')->nullable()->after('auto_gather_target');
            $table->timestamp('auto_gather_last_tick_at')->nullable()->after('auto_gather_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['auto_gather_skill', 'auto_gather_target', 'auto_gather_expires_at', 'auto_gather_last_tick_at']);
        });
    }
};
