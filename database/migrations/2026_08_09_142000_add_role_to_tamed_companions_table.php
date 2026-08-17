<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            // Copied from the source Monster's role at tame time (CombatService::attemptTame()) — drives
            // a small AI behavior choice in battle (see CombatService::applyCompanionAttacks()) instead of
            // every companion always just attacking.
            $table->string('role')->default('brute')->after('is_elite');
        });

        // Backfill existing already-tamed companions from their source monster's role where that link
        // still resolves, so no pre-existing companion is left in an undefined state.
        DB::statement('
            update tamed_companions tc
            inner join monsters m on m.id = tc.monster_id
            set tc.role = m.role
        ');
    }

    public function down(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
