<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_entries', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('glyph');
            $table->string('artist_name')->nullable()->after('image_path');
            // Everything that doesn't belong on the compact card — full grade/tier tables, ability
            // lists, recipe materials, sibling rarities — kept out of `stats` so the card grid stays a
            // clean, scannable headline and the rest lives behind a per-entry details expansion.
            $table->json('details')->nullable()->after('stats');
        });
    }

    public function down(): void
    {
        Schema::table('wiki_entries', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'artist_name', 'details']);
        });
    }
};
