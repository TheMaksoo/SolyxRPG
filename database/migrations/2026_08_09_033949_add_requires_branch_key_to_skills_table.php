<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Replaces the old boolean requires_profession (which only ever checked "is spec_class set at all",
     * not which branch) with the exact ClassProgression key a signature skill is gated behind — checked
     * against all 5 of the character's tier-pick columns in CharacterController::unlockSkill(). */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->string('requires_branch_key')->nullable()->after('requires_profession');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('requires_branch_key');
        });
    }
};
