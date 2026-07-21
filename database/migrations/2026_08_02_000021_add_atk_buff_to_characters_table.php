<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('atk_buff_pct')->default(0)->after('mana_regen_buff_expires_at');
            $table->unsignedInteger('atk_buff_fights_left')->default(0)->after('atk_buff_pct');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['atk_buff_pct', 'atk_buff_fights_left']);
        });
    }
};
