<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Turn-based replacement for cooldown_seconds — "usable again in N rounds" rather than N real-world
     * seconds. cooldown_seconds is kept (not dropped) since it's still read by WikiSyncService/GM tooling
     * and old rows still carry it; combat now gates on cooldown_rounds instead. */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->unsignedInteger('cooldown_rounds')->default(0)->after('cooldown_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('cooldown_rounds');
        });
    }
};
