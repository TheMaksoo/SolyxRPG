<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Widens the class-ladder from 3 tiers (t20/t40/t60) to 5 (t20/t50/t100/t150/t200) and relaxes the
     * unique index from (base_class, tier, key) to (base_class, key) — ClassSeeder now relevels an
     * existing t40/t60 row's tier in place rather than treating tier as part of a row's identity, so key
     * alone (already unique per class) is the right constraint going forward. */
    public function up(): void
    {
        DB::statement("ALTER TABLE class_progressions MODIFY tier ENUM('t20', 't40', 't50', 't60', 't100', 't150', 't200') NOT NULL");

        Schema::table('class_progressions', function (Blueprint $table) {
            $table->dropUnique(['base_class', 'tier', 'key']);
            $table->unique(['base_class', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('class_progressions', function (Blueprint $table) {
            $table->dropUnique(['base_class', 'key']);
            $table->unique(['base_class', 'tier', 'key']);
        });

        DB::statement("ALTER TABLE class_progressions MODIFY tier ENUM('t20', 't40', 't60') NOT NULL");
    }
};
