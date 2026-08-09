<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: appends 'pet_food' to the items.type enum — fed to a tamed companion (see
        // TamedCompanionController::feed) to grant it XP. Not usable via the normal in-battle 'item'
        // action (CombatService rejects it there).
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield', 'pet_food') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield') NOT NULL");
    }
};
