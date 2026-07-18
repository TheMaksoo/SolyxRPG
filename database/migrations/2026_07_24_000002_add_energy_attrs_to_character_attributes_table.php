<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->unsignedInteger('energy_cap')->default(0)->after('mana_regen');
            $table->unsignedInteger('energy_regen')->default(0)->after('energy_cap');
            $table->unsignedInteger('trade_speed')->default(0)->after('energy_regen');
        });
    }

    public function down(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->dropColumn(['energy_cap', 'energy_regen', 'trade_speed']);
        });
    }
};
