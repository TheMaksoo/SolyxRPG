<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->timestamp('cooldown_expires_at')->nullable()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->dropColumn('cooldown_expires_at');
        });
    }
};
