<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            // Filename only (e.g. "meadow_rat.svg"), resolved against public/images/monsters/ on the
            // frontend — null falls back to rendering the emoji `glyph` like every monster does today, so
            // art can be added one monster at a time instead of needing full coverage up front.
            $table->string('sprite')->nullable()->after('glyph');
        });
    }

    public function down(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            $table->dropColumn('sprite');
        });
    }
};
