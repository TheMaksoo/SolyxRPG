<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reforge/enchant level (0-5, see ReforgeService) for this specific owned gear instance — read in
     * Character::effectiveStats()'s equipped-gear loop to scale that item's stat_json up. */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedTinyInteger('enchant_level')->default(0)->after('durability_max');
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('enchant_level');
        });
    }
};
