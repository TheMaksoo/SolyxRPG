<?php

namespace App\Services;

use App\Models\Character;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;

class ReforgeService
{
    /** Combat gear types eligible for reforging — deliberately excludes gathering tools
     * (pickaxe/axe/sickle/hammer), which CraftingController::GEAR_TYPES also covers but which have no
     * place being permanently enchanted. */
    private const REFORGEABLE_TYPES = ['weapon', 'armor', 'shield', 'quiver', 'trinket'];

    private const DEFAULT_GOLD = [1 => 5000, 2 => 20000, 3 => 55000, 4 => 95000, 5 => 150000];
    private const DEFAULT_GEMS = [1 => 10, 2 => 20, 3 => 35, 4 => 45, 5 => 60];
    private const DEFAULT_MATERIAL_QTY = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5];

    /** Cost to take $gearRow from its current enchant_level to the next one. */
    public function nextLevelCost(Inventory $gearRow): array
    {
        $nextLevel = $gearRow->enchant_level + 1;

        return [
            'level' => $nextLevel,
            'gold' => (int) GameConfig::number("reforge_cost_gold_level_{$nextLevel}", self::DEFAULT_GOLD[$nextLevel] ?? 0),
            'gems' => (int) GameConfig::number("reforge_cost_gems_level_{$nextLevel}", self::DEFAULT_GEMS[$nextLevel] ?? 0),
            'material_qty' => (int) GameConfig::number("reforge_cost_material_qty_level_{$nextLevel}", self::DEFAULT_MATERIAL_QTY[$nextLevel] ?? 0),
        ];
    }

    public function maxLevel(): int
    {
        return (int) GameConfig::number('reforge_max_level', 5);
    }

    public function unlockLevel(): int
    {
        return (int) GameConfig::number('reforge_unlock_level', 200);
    }

    /** Enchants $gearRow up by one level, deducting gold/gems/Reforging Catalyst. Throws (via abort_if)
     * on every failure condition rather than returning a bool, matching this codebase's controller-facing
     * service convention (see TamedCompanionController::revive, CraftingController::craft). */
    public function reforge(Character $character, Inventory $gearRow): Inventory
    {
        abort_if($gearRow->character_id !== $character->id, 403, 'That gear belongs to a different character.');
        abort_if(! in_array($gearRow->item->type, self::REFORGEABLE_TYPES, true), 422, 'That item cannot be reforged.');
        abort_if($character->level < $this->unlockLevel(), 422, "Requires character level {$this->unlockLevel()}.");
        abort_if($gearRow->enchant_level >= $this->maxLevel(), 422, 'Already at max reforge level.');

        $cost = $this->nextLevelCost($gearRow);

        abort_if($character->gold < $cost['gold'], 422, "Requires {$cost['gold']} gold.");
        abort_if(($character->user->gems ?? 0) < $cost['gems'], 422, "Requires {$cost['gems']} gems.");

        $material = Item::where('key', 'reforging_catalyst')->first();
        $owned = $material ? Inventory::where('character_id', $character->id)->where('item_id', $material->id)->first() : null;
        abort_if(! $owned || $owned->qty < $cost['material_qty'], 422, "Requires {$cost['material_qty']} Reforging Catalyst — only found in loot crates.");

        $character->decrement('gold', $cost['gold']);
        $character->user->decrement('gems', $cost['gems']);
        GemLedger::log($character->user, -$cost['gems'], 'reforge', $character);

        $owned->decrement('qty', $cost['material_qty']);
        if ($owned->fresh()->qty <= 0) {
            $owned->delete();
        }

        $gearRow->increment('enchant_level');

        return $gearRow->fresh('item');
    }
}
