<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('active_title_id')->nullable()->after('quests_completed')->constrained('cosmetics')->nullOnDelete();
            $table->foreignId('active_color_id')->nullable()->after('active_title_id')->constrained('cosmetics')->nullOnDelete();
            $table->foreignId('active_banner_id')->nullable()->after('active_color_id')->constrained('cosmetics')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_title_id');
            $table->dropConstrainedForeignId('active_color_id');
            $table->dropConstrainedForeignId('active_banner_id');
        });
    }
};
