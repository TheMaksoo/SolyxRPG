<?php

use App\Models\Item;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Drives which flavor verb ("slash", "chop down on", "fire an arrow at"...) a weapon's attacks use in the combat log. */
    private const CATEGORY_BY_KEY = [
        'ashfang_blade' => 'sword',
        'silvered_blade' => 'sword',
        'gilded_saber' => 'sword',
        'shadow_dagger' => 'dagger',
        'stone_shiv' => 'dagger',
        'iron_dagger' => 'dagger',
        'emberbow' => 'bow',
        'wooden_bow' => 'bow',
        'void_staff' => 'staff',
    ];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('weapon_category')->nullable()->after('type');
        });

        foreach (self::CATEGORY_BY_KEY as $key => $category) {
            Item::where('key', $key)->update(['weapon_category' => $category]);
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('weapon_category');
        });
    }
};
