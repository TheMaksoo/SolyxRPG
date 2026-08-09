<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            // Death (hp hits 0 in battle) and release are both permanent — the row is archived, never
            // deleted, so TamedCompanionLog entries always have something to join back to. A companion
            // only counts against the roster cap while archived_at is null.
            $table->timestamp('archived_at')->nullable()->after('active');
            $table->enum('archived_reason', ['died', 'freed'])->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('tamed_companions', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_reason']);
        });
    }
};
