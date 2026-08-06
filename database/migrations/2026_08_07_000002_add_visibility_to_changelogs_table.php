<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('changelogs', function (Blueprint $table) {
            // Controls which roles can see this entry:
            //   player  — everyone (default)
            //   tester  — testers + GMs only
            //   gm      — GMs only
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
