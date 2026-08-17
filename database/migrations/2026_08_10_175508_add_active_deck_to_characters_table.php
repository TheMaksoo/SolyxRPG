<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Up to 6 unlocked ACTIVE skill ids the player has slotted for battle — see
            // CharacterController::setActiveDeck(). Null/empty means "no deck set yet", which
            // BattlePage.vue/CombatService treat as "show every unlocked active" so existing characters
            // aren't soft-locked out of skills they already have the moment this ships.
            $table->json('active_deck_json')->nullable()->after('skill_points');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('active_deck_json');
        });
    }
};
