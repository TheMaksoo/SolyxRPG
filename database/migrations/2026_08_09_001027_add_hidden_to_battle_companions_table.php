<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battle_companions', function (Blueprint $table) {
            // Per-battle only — this table is recreated fresh every fight (see CombatService::start()),
            // so a hidden companion automatically fights normally again starting next battle with no
            // extra reset logic needed.
            $table->boolean('hidden')->default(false)->after('hp_max');
        });
    }

    public function down(): void
    {
        Schema::table('battle_companions', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
