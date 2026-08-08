<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('changelogs', function (Blueprint $table) {
            // 'player' = visible to all; 'tester' = testers + GMs; 'gm' = GMs only
            $table->string('visibility')->default('player')->after('tag');
        });
    }

    public function down(): void
    {
        Schema::table('changelogs', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
