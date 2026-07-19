<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('times_mined')->default(0)->after('bosses_slain');
            $table->unsignedInteger('times_chopped')->default(0)->after('times_mined');
            $table->unsignedInteger('times_smelted')->default(0)->after('times_chopped');
            $table->unsignedInteger('times_foraged')->default(0)->after('times_smelted');
            $table->unsignedInteger('times_crafted')->default(0)->after('times_foraged');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['times_mined', 'times_chopped', 'times_smelted', 'times_foraged', 'times_crafted']);
        });
    }
};
