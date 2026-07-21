<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: appends 'shield' to the items.type enum for the warrior's second, simultaneously-
        // equippable slot (alongside their chest armor) — see ItemSeeder/CraftingController/InventoryController.
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver') NOT NULL");
    }
};
