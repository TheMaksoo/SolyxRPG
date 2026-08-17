<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Same "filename base, glyph is the fallback" convention Monster's own `sprite` column already uses
     * (see 2026_08_11_210000_add_sprite_to_monsters_table) — extended here so the new GM art pipeline has
     * somewhere real to write an approved image for every other content type artists are asked to cover. */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('sprite')->nullable()->after('glyph');
        });
        Schema::table('skills', function (Blueprint $table) {
            $table->string('sprite')->nullable()->after('glyph');
        });
        Schema::table('zones', function (Blueprint $table) {
            $table->string('sprite')->nullable()->after('glyph');
        });
        Schema::table('dungeons', function (Blueprint $table) {
            $table->string('sprite')->nullable()->after('glyph');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('sprite');
        });
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('sprite');
        });
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('sprite');
        });
        Schema::table('dungeons', function (Blueprint $table) {
            $table->dropColumn('sprite');
        });
    }
};
