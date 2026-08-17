<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds the first *permanent* gear-granted mana-regen stat — every existing mana-regen key
// (`mana_regen_pct_buff`) is a timed consumable buff, not an always-on equip bonus. This backs
// the new mage Trinket line (see Character::gearManaRegenFlat()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->integer('mana_regen_flat')->nullable()->after('mp');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('mana_regen_flat');
        });
    }
};
