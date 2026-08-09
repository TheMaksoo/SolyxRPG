<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            // Snapshot of the encounter grade (common/elite/champion/legendary) it was tamed at — see
            // GradeService::TIERS. Distinct from is_elite (the monster's own permanent elite flag).
            $table->string('grade')->default('common')->after('is_elite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            $table->dropColumn('grade');
        });
    }
};
