<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Companions get a small MP pool (regenerates a flat amount per round, see
     * CombatService::resolveCompanionTurn()) so their role-flavored special ability — previously just an
     * always-on branch (mender heals whenever someone's hurt) — costs a real resource instead of firing
     * unconditionally, matching how every other MP-gated ability in this game works. */
    public function up(): void
    {
        Schema::table('battle_companions', function (Blueprint $table) {
            $table->unsignedInteger('mp')->default(100)->after('hp_max');
            $table->unsignedInteger('mp_max')->default(100)->after('mp');
        });
    }

    public function down(): void
    {
        Schema::table('battle_companions', function (Blueprint $table) {
            $table->dropColumn(['mp', 'mp_max']);
        });
    }
};
