<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            // Positions a signature-branch skill inside its own small tree: 'trunk_1' / 'trunk_2' for the
            // two lead-in passive nodes, null for the branch's capstone (the pre-existing single-unlock
            // skill row). Purely unlock-order/display metadata — see CharacterController::unlockSkill()
            // and SkillsPage.vue.
            $table->string('node_slot')->nullable()->after('requires_branch_key');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('node_slot');
        });
    }
};
