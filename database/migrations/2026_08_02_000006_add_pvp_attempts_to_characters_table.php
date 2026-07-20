<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('pvp_attempts_used')->default(0)->after('gems');
            $table->timestamp('pvp_attempts_reset_at')->nullable()->after('pvp_attempts_used');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['pvp_attempts_used', 'pvp_attempts_reset_at']);
        });
    }
};
