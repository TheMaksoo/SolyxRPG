<?php

use App\Models\Inventory;
use App\Models\Item;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GEAR_TYPES = ['weapon', 'armor', 'pickaxe', 'axe'];

    /** Higher grade gear has more max durability. Frozen here rather than referencing DurabilityService, per migration convention. */
    private const MAX_DURABILITY_BY_RARITY = [
        'common' => 100,
        'rare' => 160,
        'epic' => 240,
        'legendary' => 350,
        'mythic' => 500,
    ];

    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedInteger('durability')->nullable()->after('slot');
            $table->unsignedInteger('durability_max')->nullable()->after('durability');
        });

        // Gear was previously qty-stacked (one row could represent several physical copies). Durability needs
        // one row per instance, so split any qty>1 gear row into individual full-durability rows.
        Inventory::whereHas('item', fn ($q) => $q->whereIn('type', self::GEAR_TYPES))->with('item')->get()
            ->each(function (Inventory $row) {
                $max = self::MAX_DURABILITY_BY_RARITY[$row->item->rarity] ?? self::MAX_DURABILITY_BY_RARITY['common'];
                $extraCopies = $row->qty - 1;

                $row->update(['qty' => 1, 'durability' => $max, 'durability_max' => $max]);

                for ($i = 0; $i < $extraCopies; $i++) {
                    Inventory::create([
                        'character_id' => $row->character_id,
                        'item_id' => $row->item_id,
                        'qty' => 1,
                        'equipped' => false,
                        'durability' => $max,
                        'durability_max' => $max,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn(['durability', 'durability_max']);
        });
    }
};
