<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Splits Vitality (hp) into HP Cap + HP Regen, and Focus (mp) into Mana Cap + Mana Regen, and adds Crit Damage. */
    public function up(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->unsignedInteger('hp_cap')->default(0)->after('armor');
            $table->unsignedInteger('hp_regen')->default(0)->after('hp_cap');
            $table->unsignedInteger('mana_cap')->default(0)->after('hp_regen');
            $table->unsignedInteger('mana_regen')->default(0)->after('mana_cap');
            $table->unsignedInteger('crit_damage')->default(0)->after('crit');
        });

        // Existing Vitality/Focus points carry over as max-HP/MP investment; regen starts fresh at 0.
        DB::statement('UPDATE character_attributes SET hp_cap = hp, mana_cap = mp');

        Schema::table('character_attributes', function (Blueprint $table) {
            $table->dropColumn(['hp', 'mp']);
        });
    }

    public function down(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->unsignedInteger('hp')->default(0)->after('armor');
            $table->unsignedInteger('mp')->default(0)->after('hp');
        });

        DB::statement('UPDATE character_attributes SET hp = hp_cap, mp = mana_cap');

        Schema::table('character_attributes', function (Blueprint $table) {
            $table->dropColumn(['hp_cap', 'hp_regen', 'mana_cap', 'mana_regen', 'crit_damage']);
        });
    }
};
