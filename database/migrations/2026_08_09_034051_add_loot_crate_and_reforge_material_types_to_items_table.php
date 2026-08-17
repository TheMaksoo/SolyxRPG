<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: appends 'loot_crate' (opened via CrateService, see crate_openings) and
        // 'reforge_material' (spent by ReforgeService, obtainable only from crates) to items.type.
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield', 'pet_food', 'pet_revive_potion', 'loot_crate', 'reforge_material') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield', 'pet_food', 'pet_revive_potion') NOT NULL");
    }
};
