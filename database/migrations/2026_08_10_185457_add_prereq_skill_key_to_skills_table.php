<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            // Explicit "unlock this exact skill first" — needed once a branch's tree stops being a
            // straight line (see SkillSeeder's treeNodes(): hub -> LEFT arm and RIGHT arm both branch
            // off the same hub in parallel, so the old "highest tier below mine in this branch" lookup
            // in CharacterController::unlockSkill() can't disambiguate which of two same-tier siblings
            // a node actually depends on). Null falls back to that old tier-based lookup, so every
            // pre-existing skill (core class skills, and node_slot IS NULL rows) is unaffected.
            $table->string('prereq_skill_key')->nullable()->after('node_slot');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('prereq_skill_key');
        });
    }
};
