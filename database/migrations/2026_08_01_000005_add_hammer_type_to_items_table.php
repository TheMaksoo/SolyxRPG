<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle', 'hammer') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE items MODIFY type ENUM('weapon', 'armor', 'consumable', 'cosmetic', 'material', 'pickaxe', 'axe', 'repair_pack', 'sickle') NOT NULL");
    }
};
