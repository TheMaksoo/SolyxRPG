<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            // Gates a skill on the character having chosen ANY t20 profession (Character::spec_class set)
            // rather than just hitting the level — see CharacterController::unlockSkill() and SkillsPage.vue.
            $table->boolean('requires_profession')->default(false)->after('class_scope');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('requires_profession');
        });
    }
};
