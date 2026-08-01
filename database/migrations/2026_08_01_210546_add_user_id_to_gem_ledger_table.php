<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gem_ledger', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->change();
        });

        // Populate user_id for existing records based on character_id
        DB::statement('
            UPDATE gem_ledger
            SET user_id = (
                SELECT user_id
                FROM characters
                WHERE characters.id = gem_ledger.character_id
            )
            WHERE character_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gem_ledger', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
