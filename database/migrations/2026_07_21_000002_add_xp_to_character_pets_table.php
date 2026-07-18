<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_pets', function (Blueprint $table) {
            $table->unsignedInteger('xp')->default(0)->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('character_pets', function (Blueprint $table) {
            $table->dropColumn('xp');
        });
    }
};
