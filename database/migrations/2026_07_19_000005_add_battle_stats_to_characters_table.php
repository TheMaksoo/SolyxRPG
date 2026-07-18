<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('battles_won')->default(0)->after('current_zone_id');
            $table->unsignedInteger('bosses_slain')->default(0)->after('battles_won');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['battles_won', 'bosses_slain']);
        });
    }
};
