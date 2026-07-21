<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Crafted-variant gear (key "{baseKey}_crafted_{random}") stores the rolled stat % here so
            // Inventory/Marketplace can show "127% roll" without re-deriving it from stat_json every time.
            // Null for every base (non-crafted) item.
            $table->integer('roll_pct')->nullable()->after('stat_json');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('roll_pct');
        });
    }
};
