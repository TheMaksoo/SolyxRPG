<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->unsignedInteger('luck')->default(0)->after('crit');
        });
    }

    public function down(): void
    {
        Schema::table('character_attributes', function (Blueprint $table) {
            $table->dropColumn('luck');
        });
    }
};
