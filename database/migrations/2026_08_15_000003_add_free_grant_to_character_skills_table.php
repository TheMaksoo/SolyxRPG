<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Marks a character_skills row granted free (rank 1 of an arm's capstone, awarded automatically when
// committing to that arm — see CharacterController::unlockSkill()) rather than paid for with a skill
// point. respecSkills()'s refund formula must skip that free rank, or every respec manufactures 1 free
// skill point per committed arm.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->boolean('free_grant')->default(false)->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->dropColumn('free_grant');
        });
    }
};
