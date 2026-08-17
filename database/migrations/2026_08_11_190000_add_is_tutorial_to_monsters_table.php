<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            // Dedicated tutorial-only monsters, never returned by the normal zone-based walk() query —
            // gives a brand-new character real monster variety without touching any zone's live balance.
            $table->boolean('is_tutorial')->default(false)->after('zone_id');
        });
    }

    public function down(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            $table->dropColumn('is_tutorial');
        });
    }
};
