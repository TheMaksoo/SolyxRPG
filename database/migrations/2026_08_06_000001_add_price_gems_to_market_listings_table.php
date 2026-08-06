<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_listings', function (Blueprint $table) {
            // Nullable — if set, the listing is priced in gems (legendary/mythic only).
            // price_gold remains on the row but is ignored when price_gems is present.
            $table->unsignedBigInteger('price_gems')->nullable()->after('price_gold');
        });
    }

    public function down(): void
    {
        Schema::table('market_listings', function (Blueprint $table) {
            $table->dropColumn('price_gems');
        });
    }
};
