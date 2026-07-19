<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Splits the single Trade Speed attribute into one per trade skill, so points can be specialized. */
    public function up(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->unsignedInteger('mining_speed')->default(0)->after('trade_speed');
            $table->unsignedInteger('chopping_speed')->default(0)->after('mining_speed');
            $table->unsignedInteger('smelting_speed')->default(0)->after('chopping_speed');
            $table->unsignedInteger('crafting_speed')->default(0)->after('smelting_speed');
        });

        // Carry over existing Trade Speed investment to all four so nobody loses power on the split.
        DB::statement('UPDATE character_attributes SET mining_speed = trade_speed, chopping_speed = trade_speed, smelting_speed = trade_speed, crafting_speed = trade_speed');

        Schema::table('character_attributes', function (Blueprint $table) {
            $table->dropColumn('trade_speed');
        });
    }

    public function down(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->unsignedInteger('trade_speed')->default(0)->after('energy_regen');
        });

        DB::statement('UPDATE character_attributes SET trade_speed = mining_speed');

        Schema::table('character_attributes', function (Blueprint $table) {
            $table->dropColumn(['mining_speed', 'chopping_speed', 'smelting_speed', 'crafting_speed']);
        });
    }
};
