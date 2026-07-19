<?php

namespace App\Services;

use App\Models\BattlePass;
use App\Models\Character;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;

class BattlePassService
{
    public const SEASON = 'ashfall';
    public const TOTAL_TIERS = 100;

    /** Tiers get steadily more expensive — tier 1 costs 100 xp, tier 100 costs 1,585 xp. Reaching tier 100
     * now takes roughly 17x the total xp the old flat-100-per-tier, 50-tier pass ever asked for. */
    private const XP_BASE = 100;
    private const XP_STEP_PER_TIER = 15;

    /** Milestones where the free/premium tracks each grant a piece of class gear instead of just currency. */
    private const CLASS_GEAR_TIERS = [25, 50, 75, 100];

    public function xpForTier(int $tier): int
    {
        return self::XP_BASE + ($tier - 1) * self::XP_STEP_PER_TIER;
    }

    /** Grants battle pass xp and processes tier-ups. Rewards are NOT auto-granted — the player claims each
     * reached tier separately (see claimTier), same as the Daily calendar. */
    public function addXp(Character $character, int $amount): array
    {
        if ($amount <= 0) {
            return ['tiers_gained' => []];
        }

        $pass = $this->passFor($character);

        $xp = $pass->xp + $amount;
        $tier = $pass->tier;
        $tiersGained = [];

        while ($tier < self::TOTAL_TIERS && $xp >= $this->xpForTier($tier + 1)) {
            $xp -= $this->xpForTier($tier + 1);
            $tier++;
            $tiersGained[] = $tier;
        }
        if ($tier >= self::TOTAL_TIERS) {
            $xp = 0;
        }

        $pass->update(['tier' => $tier, 'xp' => $xp]);

        return ['tiers_gained' => $tiersGained];
    }

    public function passFor(Character $character): BattlePass
    {
        return $character->battlePasses()->firstOrCreate(
            ['season' => self::SEASON],
            ['tier' => 0, 'xp' => 0, 'premium' => false, 'claimed_free_tiers' => [], 'claimed_premium_tiers' => []]
        );
    }

    /** The raw item definitions for a tier/track — 'class_gear' entries resolve to that character's own
     * class weapon/armor rather than a fixed key, since every class crafts something different. */
    private function itemDefsForTier(int $tier, bool $premium): array
    {
        $items = [];

        if ($tier % 5 === 0) {
            $packKey = match (true) {
                $tier < 20 => 'common_repair_pack',
                $tier < 40 => 'rare_repair_pack',
                $tier < 60 => 'epic_repair_pack',
                $tier < 80 => 'legendary_repair_pack',
                default => 'mythic_repair_pack',
            };
            $items[] = ['key' => $packKey, 'qty' => $premium ? 4 : 2];
        }

        if ($tier % 10 === 0) {
            $oreKey = match (true) {
                $tier < 30 => 'iron_bar',
                $tier < 60 => 'silver_bar',
                $tier < 90 => 'gold_bar',
                default => 'mythril_bar',
            };
            $items[] = ['key' => $oreKey, 'qty' => $premium ? 10 : 5];
        }

        if (in_array($tier, self::CLASS_GEAR_TIERS, true)) {
            $items[] = ['class_gear' => $premium ? 'armor' : 'weapon', 'qty' => 1];
        }

        if ($premium && $tier === self::TOTAL_TIERS) {
            $items[] = ['class_gear' => 'weapon', 'qty' => 1];
        }

        return $items;
    }

    /** Resolves an item definition to a real Item — 'class_gear' entries depend on the character's base_class.
     * Restricted to 'common' rarity so this always lands on the basic craftable class item (e.g. Iron Sword),
     * never one of the same class's premium shop-exclusive epic/legendary pieces (e.g. Ashfang Blade). */
    private function resolveItem(array $itemDef, Character $character): ?Item
    {
        return isset($itemDef['class_gear'])
            ? Item::where('class_key', $character->base_class)->where('type', $itemDef['class_gear'])->where('rarity', 'common')->first()
            : Item::where('key', $itemDef['key'])->first();
    }

    /** Gold/gems for a tier/track, before item rewards. */
    private function currencyForTier(int $tier, bool $premium): array
    {
        $goldBase = $premium ? 30 : 20;
        $goldPerTier = $premium ? 8 : 5;
        $milestoneGems = $premium ? 15 : 8;

        $gold = $goldBase + $tier * $goldPerTier;
        $gems = $tier % 5 === 0 ? $milestoneGems : 0;
        if ($premium && $tier === self::TOTAL_TIERS) {
            $gems += 200;
        }

        return [$gold, $gems];
    }

    /** What a tier's reward looks like — used both to preview the whole track (with item names resolved for
     * this character) and to actually grant on claim. */
    public function rewardForTier(int $tier, bool $premium, Character $character): array
    {
        [$gold, $gems] = $this->currencyForTier($tier, $premium);

        $items = collect($this->itemDefsForTier($tier, $premium))
            ->map(function (array $itemDef) use ($character) {
                $item = $this->resolveItem($itemDef, $character);

                return $item ? ['name' => $item->name, 'glyph' => $item->glyph, 'qty' => $itemDef['qty']] : null;
            })
            ->filter()
            ->values()
            ->all();

        return ['gold' => $gold, 'gems' => $gems, 'items' => $items];
    }

    /** Claims a single tier's reward on one track. Returns null if the tier isn't reached yet, the premium
     * track isn't unlocked, or it's already been claimed. */
    public function claimTier(Character $character, int $tier, string $track): ?array
    {
        $pass = $this->passFor($character);

        if ($tier < 1 || $tier > $pass->tier) {
            return null;
        }
        if ($track === 'premium' && ! $pass->premium) {
            return null;
        }

        $claimedField = $track === 'premium' ? 'claimed_premium_tiers' : 'claimed_free_tiers';
        $claimed = $pass->$claimedField ?? [];
        if (in_array($tier, $claimed, true)) {
            return null;
        }

        $premium = $track === 'premium';
        [$gold, $gems] = $this->currencyForTier($tier, $premium);

        if ($gold > 0) {
            $character->increment('gold', $gold);
        }
        if ($gems > 0) {
            $character->increment('gems', $gems);
            GemLedger::log($character, $gems, "battlepass_claim:tier{$tier}_{$track}");
        }

        $grantedItems = [];
        foreach ($this->itemDefsForTier($tier, $premium) as $itemDef) {
            $item = $this->resolveItem($itemDef, $character);
            if (! $item) {
                continue;
            }

            $qty = $itemDef['qty'];
            if (in_array($item->type, ['weapon', 'armor'], true)) {
                for ($i = 0; $i < $qty; $i++) {
                    Inventory::create([
                        'character_id' => $character->id, 'item_id' => $item->id, 'qty' => 1, 'equipped' => false,
                        'durability' => 100, 'durability_max' => 100,
                    ]);
                }
            } else {
                $inventory = Inventory::firstOrNew(['character_id' => $character->id, 'item_id' => $item->id, 'equipped' => false]);
                $inventory->qty = ($inventory->qty ?? 0) + $qty;
                $inventory->save();
            }

            $grantedItems[] = ['name' => $item->name, 'glyph' => $item->glyph, 'qty' => $qty];
        }

        $claimed[] = $tier;
        $pass->update([$claimedField => $claimed]);

        return ['gold' => $gold, 'gems' => $gems, 'items' => $grantedItems];
    }

    /** Claims every reached-but-unclaimed tier on the free track, and on the premium track too if unlocked.
     * Returns the aggregate total across everything granted. */
    public function claimAll(Character $character): array
    {
        $pass = $this->passFor($character);
        $totals = ['gold' => 0, 'gems' => 0, 'items' => [], 'tiers_claimed' => 0];

        $tracks = $pass->premium ? ['free', 'premium'] : ['free'];
        foreach ($tracks as $track) {
            $claimedField = $track === 'premium' ? 'claimed_premium_tiers' : 'claimed_free_tiers';
            $claimed = $pass->$claimedField ?? [];

            for ($tier = 1; $tier <= $pass->tier; $tier++) {
                if (in_array($tier, $claimed, true)) {
                    continue;
                }

                $result = $this->claimTier($character, $tier, $track);
                if (! $result) {
                    continue;
                }

                $totals['gold'] += $result['gold'];
                $totals['gems'] += $result['gems'];
                $totals['items'] = array_merge($totals['items'], $result['items']);
                $totals['tiers_claimed']++;
            }
        }

        return $totals;
    }
}
