<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The "Hide Pet" mid-battle action has been removed — this column backed it and is no longer read anywhere.
        Schema::table('battle_companions', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }

    public function down(): void
    {
        Schema::table('battle_companions', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('hp_max');
        });
    }
};
