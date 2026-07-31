<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('bio', 160)->nullable()->after('showcased_achievement_ids');
            $table->json('playstyle_tags')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['bio', 'playstyle_tags']);
        });
    }
};
