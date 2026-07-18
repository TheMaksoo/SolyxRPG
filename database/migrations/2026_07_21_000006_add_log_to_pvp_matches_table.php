<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pvp_matches', function (Blueprint $table) {
            $table->json('log_json')->nullable()->after('rating_delta');
        });
    }

    public function down(): void
    {
        Schema::table('pvp_matches', function (Blueprint $table) {
            $table->dropColumn('log_json');
        });
    }
};
