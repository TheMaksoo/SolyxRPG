<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Gates a specialty-gear recipe to a specific ClassProgression branch key, checked in
     * CraftingController::craft() alongside the existing level/material checks. */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('requires_branch_key')->nullable()->after('min_level');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('requires_branch_key');
        });
    }
};
