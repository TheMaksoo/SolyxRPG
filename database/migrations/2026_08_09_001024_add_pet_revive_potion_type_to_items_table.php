<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: appends 'pet_revive_potion' to the items.type enum — used on a downed companion
        // (see TamedCompanionController::revive) for a chance to bring it back. Not usable via the
        // normal in-battle 'item' action, same restriction as pet_food.
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield', 'pet_food', 'pet_revive_potion') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer', 'quiver', 'shield', 'pet_food') NOT NULL");
    }
};
