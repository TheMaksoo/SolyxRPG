<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_trade_skills', function (Blueprint $table) {
            $table->string('last_action_target')->nullable()->after('last_action_at');
        });
    }

    public function down(): void
    {
        Schema::table('character_trade_skills', function (Blueprint $table) {
            $table->dropColumn('last_action_target');
        });
    }
};
