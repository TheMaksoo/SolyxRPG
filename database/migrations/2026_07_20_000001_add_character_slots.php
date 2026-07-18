<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // MySQL needs a backing index for the FK before the unique index can be dropped.
            $table->index('user_id', 'characters_user_id_index');
        });
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('active_character_id')->nullable()->after('id')->nullOnDelete();
            $table->unsignedTinyInteger('bonus_character_slots')->default(0)->after('active_character_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_character_id');
            $table->dropColumn('bonus_character_slots');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->unique('user_id');
        });
        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex('characters_user_id_index');
        });
    }
};
