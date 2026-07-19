<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEVEL_BY_RARITY = [
        'common' => 1,
        'rare' => 8,
        'epic' => 20,
        'legendary' => 35,
        'mythic' => 55,
    ];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('min_level')->default(1)->after('rarity');
        });

        foreach (self::LEVEL_BY_RARITY as $rarity => $level) {
            DB::table('items')->where('rarity', $rarity)->update(['min_level' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('min_level');
        });
    }
};
