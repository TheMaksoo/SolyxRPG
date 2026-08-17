<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: appends 'trinket', the new universal 3rd accessory slot every class gets
        // alongside their weapon/armor and their class's shield/quiver 2nd slot (see ItemSeeder).
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield', 'pet_food', 'pet_revive_potion', 'loot_crate', 'reforge_material', 'trinket') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield', 'pet_food', 'pet_revive_potion', 'loot_crate', 'reforge_material') NOT NULL");
    }
};
