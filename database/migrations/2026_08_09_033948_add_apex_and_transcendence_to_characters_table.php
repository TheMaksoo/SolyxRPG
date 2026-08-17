<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Two new class-ladder tiers (t150 Apex, t200 Transcendence) extending the existing spec_class(t20)/
     * profession(t50, was t40)/ascension(t100, was t60) chain — see CharacterController::chooseProfession(). */
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('apex_path')->nullable()->after('ascension');
            $table->string('transcendence')->nullable()->after('apex_path');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['apex_path', 'transcendence']);
        });
    }
};
