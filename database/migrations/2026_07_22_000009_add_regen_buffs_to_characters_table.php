<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('hp_regen_buff_pct')->default(0)->after('last_mana_regen_at');
            $table->timestamp('hp_regen_buff_expires_at')->nullable()->after('hp_regen_buff_pct');
            $table->unsignedInteger('mana_regen_buff_pct')->default(0)->after('hp_regen_buff_expires_at');
            $table->timestamp('mana_regen_buff_expires_at')->nullable()->after('mana_regen_buff_pct');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['hp_regen_buff_pct', 'hp_regen_buff_expires_at', 'mana_regen_buff_pct', 'mana_regen_buff_expires_at']);
        });
    }
};
