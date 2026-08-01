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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('gems')->default(0)->after('id');
        });

        // Migrate existing character gems to user accounts (sum all characters' gems per user)
        DB::statement('
            UPDATE users
            SET gems = (
                SELECT COALESCE(SUM(characters.gems), 0)
                FROM characters
                WHERE characters.user_id = users.id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gems');
        });
    }
};
