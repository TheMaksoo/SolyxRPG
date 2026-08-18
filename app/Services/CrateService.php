<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CrateOpening;
use App\Models\GameConfig;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;

class CrateService
{
    private const DEFAULT_UNLOCK_MINUTES = ['common' => 5, 'rare' => 60, 'epic' => 480, 'legendary' => 1440, 'mythic' => 4320];

    /** Reward ranges per lootbox rarity — gold always rolls; gems ONLY roll on Legendary/Mythic (the key
     * is simply absent below that tier). Bigger rarities also roll better material odds/amounts AND a
     * better chance at a Reforging Catalyst, so a higher-rarity box reliably feels like a bigger payout
     * on every axis, not just gold. */
    private const REWARD_TABLE = [
        'common' => ['gold' => [200, 800], 'material_chance' => 60, 'material_qty' => [2, 5], 'pet_food_chance' => 25, 'reforge_material_chance' => 0],
        'rare' => ['gold' => [800, 3000], 'material_chance' => 65, 'material_qty' => [3, 6], 'pet_food_chance' => 45, 'reforge_material_chance' => 15],
        'epic' => ['gold' => [3000, 12000], 'material_chance' => 70, 'material_qty' => [3, 7], 'pet_food_chance' => 65, 'reforge_material_chance' => 40],
        'legendary' => ['gold' => [12000, 40000], 'gems' => [60, 180], 'material_chance' => 75, 'material_qty' => [4, 8], 'pet_food_chance' => 90, 'reforge_material_chance' => 60],
        'mythic' => ['gold' => [40000, 120000], 'gems' => [180, 500], 'material_chance' => 80, 'material_qty' => [5, 10], 'pet_food_chance' => 100, 'reforge_material_chance' => 90],
    ];

    /** Read-only access to REWARD_TABLE for callers outside combat resolution — currently just
     * WikiSyncService, so the wiki's crate acquisition info can never drift from the real reward table. */
    public static function rewardTableFor(string $rarity): ?array
    {
        return self::REWARD_TABLE[$rarity] ?? null;
    }

    /** Location-bound material contents (see database/seeders/ItemSeeder.php's material rarity ladder,
     * which skips a "rare" tier — mining/woodchopping/foraging materials only come in common/epic/
     * legendary/mythic). A low-danger zone's box mostly rolls `pool`'s early (basic) entries; a
     * high-danger zone's box mostly rolls its later (rarer) entries — `array_rand` weighted by simple
     * repetition below. `chance_mult`/`qty_mult` shrink the base material_chance/material_qty from
     * REWARD_TABLE as danger rises, so a higher zone's box is pickier about handing out its (better)
     * materials at all — "location-bound... higher zones even lower drop rates and amounts" per the
     * design brief. */
    private const ZONE_MATERIAL_TABLE = [
        'safe' => ['pool' => ['common', 'common', 'common', 'epic'], 'chance_mult' => 1.0, 'qty_mult' => 1.0],
        'medium' => ['pool' => ['common', 'common', 'epic'], 'chance_mult' => 0.9, 'qty_mult' => 0.85],
        'high' => ['pool' => ['epic', 'epic', 'legendary'], 'chance_mult' => 0.75, 'qty_mult' => 0.7],
        'deadly' => ['pool' => ['legendary', 'legendary', 'legendary', 'mythic'], 'chance_mult' => 0.6, 'qty_mult' => 0.55],
    ];

    /** Consumes 1 crate from the given inventory row and starts its unlock timer. Rewards are rolled and
     * frozen into rewards_json right now — not re-rolled at collect() — so a later GM balance change
     * never retroactively affects a crate a player already opened. Only one lootbox may be unlocking at
     * once per character (see maxConcurrentUnlocks()) — Premium raises that cap, it never removes it. */
    public function open(Character $character, Inventory $crateRow): CrateOpening
    {
        $item = $crateRow->item;
        abort_if($item->type !== 'loot_crate', 422, 'That item is not a loot crate.');

        $activeCount = CrateOpening::where('character_id', $character->id)->whereNull('collected_at')->count();
        $maxSlots = $this->maxConcurrentUnlocks($character);
        abort_if($activeCount >= $maxSlots, 422, "You already have {$maxSlots} lootbox".($maxSlots > 1 ? 'es' : '')." unlocking at once — collect one first, or upgrade Premium for more concurrent slots.");

        $rarity = $item->rarity ?? 'common';

        $crateRow->decrement('qty');
        if ($crateRow->fresh()->qty <= 0) {
            $crateRow->delete();
        }

        return CrateOpening::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'rarity' => $rarity,
            'rewards_json' => $this->rollRewards($rarity, $character->zone?->danger),
            'started_at' => now(),
            'completes_at' => now()->addMinutes($this->unlockMinutesFor($rarity, $character)),
        ]);
    }

    /** How many lootboxes may sit unlocking at once for $character — base 1, +1 per VIP tier
     * (User::VIP_TIER_LOOTBOX_SLOTS). */
    public function maxConcurrentUnlocks(Character $character): int
    {
        return 1 + $character->user->vipLootboxExtraSlots();
    }

    private function unlockMinutesFor(string $rarity, Character $character): float
    {
        $base = GameConfig::number("loot_crate_{$rarity}_unlock_minutes", self::DEFAULT_UNLOCK_MINUTES[$rarity] ?? 5);
        $reduction = $character->user->vipLootboxTimeReductionPct();

        return $reduction > 0 ? $base * (1 - $reduction / 100) : $base;
    }

    /** Grants every rolled reward and marks the opening collected. Caller (CrateController) is
     * responsible for the isReady()/ownership checks before calling this. */
    public function collect(CrateOpening $opening): array
    {
        $character = $opening->character;

        foreach ($opening->rewards_json as $reward) {
            match ($reward['type']) {
                'gold' => $character->increment('gold', $reward['amount']),
                'gems' => $this->grantGems($character, $reward['amount']),
                'item' => $this->grantItem($character, $reward['item_key'], $reward['qty']),
            };
        }

        $opening->update(['collected_at' => now()]);

        return $this->describeRewards($opening->rewards_json);
    }

    /** Enriches item-type reward rows with a display name/glyph so the frontend can show a real reward
     * summary (mirrors the Auto-Battle/Auto-Gather/Battle Pass "claim summary" cards) without a second
     * inventory lookup — gold/gems rows pass through unchanged. */
    private function describeRewards(array $rewards): array
    {
        return array_map(function (array $reward) {
            if ($reward['type'] !== 'item') {
                return $reward;
            }
            $item = Item::where('key', $reward['item_key'])->first();

            return [...$reward, 'name' => $item?->name ?? $reward['item_key'], 'glyph' => $item?->glyph ?? '📦'];
        }, $rewards);
    }

    private function grantGems(Character $character, int $amount): void
    {
        $character->user->increment('gems', $amount);
        GemLedger::log($character->user, $amount, 'loot_crate', $character);
    }

    private function grantItem(Character $character, string $itemKey, int $qty): void
    {
        $item = Item::where('key', $itemKey)->first();
        if (! $item) {
            return;
        }

        $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
        $inventory->qty = ($inventory->qty ?? 0) + $qty;
        $inventory->save();
    }

    /** $zoneDanger is the character's CURRENT zone at open() time (null defaults to 'safe') — a lootbox's
     * material contents are location-bound to wherever it's actually unlocked, not to wherever the
     * monster that dropped it died (crates of the same rarity stack into one inventory qty, so no
     * per-drop origin survives long enough to read back here anyway). */
    private function rollRewards(string $rarity, ?string $zoneDanger): array
    {
        $table = self::REWARD_TABLE[$rarity];
        $zoneTable = self::ZONE_MATERIAL_TABLE[$zoneDanger ?? 'safe'] ?? self::ZONE_MATERIAL_TABLE['safe'];

        $rewards = [
            ['type' => 'gold', 'amount' => random_int($table['gold'][0], $table['gold'][1])],
        ];

        // Gems are exclusive to Legendary/Mythic — REWARD_TABLE simply omits the key below that tier.
        if (isset($table['gems'])) {
            $rewards[] = ['type' => 'gems', 'amount' => random_int($table['gems'][0], $table['gems'][1])];
        }

        $materialChance = $table['material_chance'] * $zoneTable['chance_mult'];
        if (random_int(1, 100) <= $materialChance) {
            $materialRarity = $zoneTable['pool'][array_rand($zoneTable['pool'])];
            // "All kinds of materials" — a random pick among every material of the rolled rarity, not
            // always the same authored key, so two boxes of the same rarity/zone can still differ.
            $material = Item::where('type', 'material')->where('rarity', $materialRarity)->inRandomOrder()->first();
            if ($material) {
                $qtyMin = max(1, (int) round($table['material_qty'][0] * $zoneTable['qty_mult']));
                $qtyMax = max($qtyMin, (int) round($table['material_qty'][1] * $zoneTable['qty_mult']));
                $rewards[] = ['type' => 'item', 'item_key' => $material->key, 'qty' => random_int($qtyMin, $qtyMax)];
            }
        }

        if (random_int(1, 100) <= $table['pet_food_chance']) {
            $rewards[] = ['type' => 'item', 'item_key' => 'pet_food', 'qty' => 1];
        }
        if ($table['reforge_material_chance'] > 0 && random_int(1, 100) <= $table['reforge_material_chance']) {
            $rewards[] = ['type' => 'item', 'item_key' => 'reforging_catalyst', 'qty' => 1];
        }

        return $rewards;
    }
}
