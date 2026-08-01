<?php

namespace App\Services;

use App\Models\BattlePass;
use App\Models\Character;
use App\Models\CharacterCosmetic;
use App\Models\Cosmetic;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Quest;

class BattlePassService
{
    public const SEASON = 'ashfall';
    public const TOTAL_TIERS = 100;

    /** The pace target used for the "projected finish" readout on the Battle Pass page — see the curve
     * sizing note below for why day 25 specifically. Stays fixed even though the season itself (see
     * seasonLengthDays) varies 28-31 days with the real calendar — a couple of days more or less slack
     * doesn't change the target worth aiming for. */
    public const REACHABLE_BY_DAY_TARGET = 25;

    /** Tiers get steadily more expensive — tier 1 costs 70 points, tier 100 costs 169, ~11,950 total to max
     * the pass. Points are quest-only (see QuestController::claim) — there is deliberately no combat or
     * daily-login source, since those were uncapped and let a single high-xp fight or one auto-battle
     * session max the entire season.
     *
     * Sized so tier 100 is reachable within the first 25 days of a real calendar month (see
     * seasonLengthDays) using ONLY the guaranteed-minimum daily/weekly/monthly quest cycles a player gets
     * regardless of which day of the week/month they start on: 25 daily resets, 3 guaranteed weekly resets
     * (floor(25/7) — a well-aligned start gets a 4th for free), and 1 guaranteed monthly reset. At current
     * QuestSeeder bp_xp values that floor is 371*25 + 650*3 + 2650 = 13,875 points — ~116% of the 11,950
     * needed, so tier 100 is genuinely reachable by day 25 even in the worst case, with real slack left
     * over (missed days, a slow week, whatever) rather than requiring every single quest to land perfectly.
     * The one-time main/raid quests, and any bonus weekly reset from a lucky start day, are pure overshoot
     * on top of that. */
    private const XP_BASE = 70;
    private const XP_STEP_PER_TIER = 1;

    /** Fallback points by quest type, used only if a quest's own reward_json omits 'bp_xp' — every seeded
     * quest sets one explicitly (see QuestSeeder). The single source of truth for "how many points does
     * this quest grant", read both when actually granting (QuestController::claim) and when projecting
     * season-long income (pointsIncomeSummary below). */
    public const DEFAULT_XP_BY_QUEST_TYPE = ['daily' => 40, 'weekly' => 200, 'monthly' => 800, 'main' => 150, 'raid' => 250];

    /** Milestones where the free/premium tracks each grant a piece of class gear instead of just currency.
     * Deliberately the SAME rarity ('common') on both tracks — Premium's edge is more currency, more
     * consumables, and exclusive cosmetics (see below), never a stat upgrade over the free track. */
    private const CLASS_GEAR_TIERS = [25, 50, 75, 100];

    /** Premium-exclusive cosmetic unlocks — purely cosmetic (title/banner/frame), never a stat or combat
     * advantage, so buying Premium is tempting without being pay-to-win. */
    private const PREMIUM_COSMETIC_TIERS = [
        50 => ['type' => 'banner', 'key_suffix' => 'veteran_banner', 'name' => 'Battle Pass Veteran', 'value' => 'linear-gradient(135deg, rgba(94,201,255,.25), rgba(232,72,47,.15))', 'rarity' => 'epic'],
        75 => ['type' => 'frame', 'key_suffix' => 'ashfall_frame', 'name' => 'Ashfall Frame', 'value' => '#ff8163', 'rarity' => 'legendary'],
        100 => ['type' => 'title', 'key_suffix' => 'champion_title', 'name' => 'Battle Pass Champion', 'value' => '🎖 Battle Pass Champion', 'rarity' => 'legendary'],
    ];

    /** Season-TOTAL repair-pack quantity by rarity index (0=common..4=mythic), on Premium — NOT a per-drop
     * amount. Repair packs drop on several tiers each (see itemDefsForTier's rotation), so this total gets
     * split evenly across however many tiers actually roll that rarity this season (see
     * rewardQuantityPlan) — deliberately inverted scale: the common bundle's season total is bulkier since
     * it's disposable anyway, the mythic one stays comparatively scarce. Free is always exactly half. */
    private const PACK_TOTAL_BY_RARITY = [256, 128, 64, 32, 16];

    /** Season-TOTAL raw-gathering-material quantity by rarity index (0=common/the base stone-wood-herb
     * item, 1=rare, 2=epic, 3=legendary, 4=mythic), on Premium — "each type" individually totals this
     * amount over the season (e.g. Stone AND Wood AND Herb each total 1028, not 1028 split three ways),
     * split evenly across however many tiers roll that exact (profession, rarity) pair (see
     * rewardQuantityPlan). Same inverted-scale logic as PACK_TOTAL_BY_RARITY. */
    private const MATERIAL_TOTAL_BY_RARITY = [1028, 512, 256, 64, 16];

    /** Ore bars reuse the raw-material rarity totals for their matching band (no separate numbers were ever
     * given for bars specifically) — iron=rare, silver=epic, gold=legendary, mythril=mythic; there's no
     * common-tier bar. Same even-spread rule as the tables above. */
    private const BAR_TOTAL_BY_RARITY = [512, 256, 64, 16];

    /** Which bar rarity (0=iron..3=mythril) each of the 10 bar-granting tiers rolls — an explicit schedule
     * rather than weightedRarityIndex, because with only 10 slots for 4 rarities that generic rotation can
     * (and did) skip a whole rarity entirely for the rest of the season. This guarantees all 4 show up at
     * least twice each, still trending toward the higher rarities on later tiers. */
    private const BAR_RARITY_BY_TIER = [10 => 0, 20 => 0, 30 => 1, 40 => 0, 50 => 1, 60 => 2, 70 => 1, 80 => 2, 90 => 3, 100 => 3];

    /** Memoized result of rewardQuantityPlan() — see that method. */
    private ?array $rewardQuantityPlan = null;

    public function xpForTier(int $tier): int
    {
        return self::XP_BASE + ($tier - 1) * self::XP_STEP_PER_TIER;
    }

    public function totalXpToMax(): int
    {
        $total = 0;
        for ($tier = 1; $tier <= self::TOTAL_TIERS; $tier++) {
            $total += $this->xpForTier($tier);
        }

        return $total;
    }

    /** The season IS the real current calendar month — 28-31 days depending on which one it is, not a
     * fixed count. There's no rollover job that resets tier/xp at the month boundary, so this only drives
     * the income/pace projections below, not an actual expiry. */
    public function seasonLengthDays(): int
    {
        return now()->daysInMonth;
    }

    /** Total points earned this season so far — every completed tier's cost plus whatever's banked toward
     * the next one. Powers the "season total" readout and pace projection on the Battle Pass page. */
    public function pointsEarnedSoFar(BattlePass $pass): int
    {
        $earned = $pass->xp;
        for ($tier = 1; $tier <= $pass->tier; $tier++) {
            $earned += $this->xpForTier($tier);
        }

        return $earned;
    }

    /** Real (not simulated) pace projection for the Battle Pass page's progress card — "days active" is
     * literally which day of the real calendar month it is today, not days since this BattlePass row was
     * created, since the season now IS the calendar month (see seasonLengthDays). There's no rollover job
     * that resets tier/xp at the month boundary, so points earned keep accumulating across months even
     * though the day-of-month counter itself rolls back to 1 — the pace projection is a rough same-month
     * read, not a claim that progress resets. Returns a null projection until the player has actually
     * earned something to project from. */
    public function seasonProgress(BattlePass $pass): array
    {
        $pointsEarned = $this->pointsEarnedSoFar($pass);
        $daysActive = now()->day;
        $dailyRate = $pointsEarned / $daysActive;
        $projectedDay = $dailyRate > 0 ? (int) ceil($this->totalXpToMax() / $dailyRate) : null;

        return [
            'points_earned' => $pointsEarned,
            'days_active' => $daysActive,
            'projected_finish_day' => $projectedDay,
            'reachable_by_day_target' => self::REACHABLE_BY_DAY_TARGET,
        ];
    }

    /** Points a single quest grants — the one place both QuestController::claim (actual grant) and
     * pointsIncomeSummary (season-long projection) read from, so the two can never drift apart. */
    public function pointsForQuest(Quest $quest): int
    {
        return $quest->reward_json['bp_xp'] ?? self::DEFAULT_XP_BY_QUEST_TYPE[$quest->type] ?? 25;
    }

    /** Projects how many points a season's worth of daily/weekly/monthly quests adds up to, versus the
     * total needed to max the pass — the "how you earn points" breakdown shown on the Battle Pass page and
     * the GM console's Progression tab. Main/raid quests are one-time bonuses on top, not part of the
     * recurring total, since they're not something the season design relies on being repeatable.
     * When $character is omitted (GM console view), class-gated quests are excluded rather than summed
     * across every class, since a player only ever sees their own class's variant of those. */
    public function pointsIncomeSummary(?Character $character = null): array
    {
        $quests = Quest::where('enabled', true)
            ->when($character, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('class_key')->orWhere('class_key', $character->base_class)))
            ->when(! $character, fn ($q) => $q->whereNull('class_key'))
            ->get()
            ->groupBy('type');

        $sumFor = fn (string $type): int => ($quests->get($type) ?? collect())
            ->sum(fn (Quest $quest) => $this->pointsForQuest($quest));

        $perDay = $sumFor('daily');
        $perWeek = $sumFor('weekly');
        $perMonth = $sumFor('monthly');
        $bonusOneTime = $sumFor('main') + $sumFor('raid');

        $seasonDays = $this->seasonLengthDays();
        $weeks = intdiv($seasonDays, 7);
        $months = 1; // the season IS one calendar month, so exactly one monthly-quest reset fits by definition

        $sources = [
            ['type' => 'daily', 'label' => 'Daily quests', 'per_cycle' => $perDay, 'cycles' => $seasonDays, 'total' => $perDay * $seasonDays],
            ['type' => 'weekly', 'label' => 'Weekly quests', 'per_cycle' => $perWeek, 'cycles' => $weeks, 'total' => $perWeek * $weeks],
            ['type' => 'monthly', 'label' => 'Monthly quests', 'per_cycle' => $perMonth, 'cycles' => $months, 'total' => $perMonth * $months],
            ['type' => 'bonus', 'label' => 'One-time main & raid quests', 'per_cycle' => $bonusOneTime, 'cycles' => 1, 'total' => $bonusOneTime],
        ];

        $recurringTotal = $perDay * $seasonDays + $perWeek * $weeks + $perMonth * $months;
        $totalToMax = $this->totalXpToMax();

        return [
            'season_length_days' => $seasonDays,
            'total_to_max' => $totalToMax,
            'sources' => $sources,
            'recurring_total' => $recurringTotal,
            'recurring_pct_of_max' => $totalToMax > 0 ? round($recurringTotal / $totalToMax * 100) : 0,
            'grand_total_with_bonus' => $recurringTotal + $bonusOneTime,
        ];
    }

    /** Grants battle pass xp (from quests only — see QuestController::claim) and processes tier-ups.
     * An active VIP subscription boosts the amount granted (see User::vipBattlePassXpBonusPct) — a
     * convenience perk on top of quest effort, never a substitute for it. Rewards are NOT auto-granted —
     * the player claims each reached tier separately (see claimTier), same as the Daily calendar. */
    public function addXp(Character $character, int $amount): array
    {
        if ($amount <= 0) {
            return ['tiers_gained' => []];
        }

        $vipBonusPct = $character->user?->vipBattlePassXpBonusPct() ?? 0.0;
        if ($vipBonusPct > 0) {
            $amount = (int) round($amount * (1 + $vipBonusPct / 100));
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
        $plan = $this->rewardQuantityPlan();

        // The bulk of the track: on every tier that isn't already a repair-pack/bar tier, hand over a raw
        // gathering material instead of leaving that tier as a pure currency drop. Rotates across mining/
        // woodchopping/foraging so no single profession's grind gets favored, and NEVER excludes any of the
        // 5 rarities from rotation regardless of tier — even tier 99 can still roll plain Stone, same as
        // tier 1 can (rarely) roll Mythril Ore. Quantity comes from rewardQuantityPlan, which sizes each
        // drop so the season TOTAL per rarity matches MATERIAL_TOTAL_BY_RARITY exactly.
        if ($tier % 5 !== 0) {
            $rarityIdx = $this->materialRarityIndex($tier);
            $freeQty = $plan['material'][$tier];
            $items[] = ['key' => $this->rawMaterialKeyForTier($tier, $rarityIdx), 'qty' => $premium ? $freeQty * 2 : $freeQty, 'category' => 'material'];
        }

        if ($tier % 5 === 0) {
            $packRarities = ['common_repair_pack', 'rare_repair_pack', 'epic_repair_pack', 'legendary_repair_pack', 'mythic_repair_pack'];
            $packUnlocked = match (true) {
                $tier < 20 => 1,
                $tier < 40 => 2,
                $tier < 60 => 3,
                $tier < 80 => 4,
                default => 5,
            };
            // window=4 (not the full 5) so 'rare' packs stay in rotation even at the deepest tiers, but
            // 'common' ages out once mythic unlocks — matches wanting rare/epic packs to keep showing up
            // late without a max-tier claim ever rolling all the way back down to a common pack.
            $rarityIdx = $this->weightedRarityIndex($tier, $packUnlocked, 4);
            $packKey = $packRarities[$rarityIdx];
            $freeQty = $plan['pack'][$tier];
            $items[] = ['key' => $packKey, 'qty' => $premium ? $freeQty * 2 : $freeQty, 'category' => 'pack'];
        }

        if ($tier % 10 === 0) {
            $barRarities = ['iron_bar', 'silver_bar', 'gold_bar', 'mythril_bar'];
            $rarityIdx = self::BAR_RARITY_BY_TIER[$tier];
            $oreKey = $barRarities[$rarityIdx];
            $freeQty = $plan['bar'][$tier];
            $items[] = ['key' => $oreKey, 'qty' => $premium ? $freeQty * 2 : $freeQty, 'category' => 'bar'];
        }

        if (in_array($tier, self::CLASS_GEAR_TIERS, true)) {
            $items[] = ['class_gear' => $premium ? 'armor' : 'weapon', 'qty' => 1, 'category' => 'gear'];
        }

        if ($premium && $tier === self::TOTAL_TIERS) {
            $items[] = ['class_gear' => 'weapon', 'qty' => 1, 'category' => 'gear'];
        }

        return $items;
    }

    /** Splits a season TOTAL evenly across the given list of tiers — the earliest tiers in the list get 1
     * extra unit when it doesn't divide evenly, so the only thing that matters (the sum) always comes out
     * exact. */
    private function distributeEvenly(array $tiers, int $total): array
    {
        $count = count($tiers);
        $base = intdiv($total, $count);
        $remainder = $total % $count;

        $qtyByTier = [];
        foreach (array_values($tiers) as $i => $tier) {
            $qtyByTier[$tier] = $base + ($i < $remainder ? 1 : 0);
        }

        return $qtyByTier;
    }

    /** Precomputes the FREE-track quantity for every material/pack/bar-granting tier so each specific
     * item's season total exactly matches MATERIAL_TOTAL_BY_RARITY / PACK_TOTAL_BY_RARITY /
     * BAR_TOTAL_BY_RARITY — split evenly across however many tiers actually roll that exact (profession,
     * rarity) combination this season (see materialRarityIndex/weightedRarityIndex), never a flat per-tier
     * amount. Built from HALF the season total (not "premium divided by 2") so free's own total can never
     * be thrown off by rounding — premium at any given tier is always exactly double what's stored here.
     * Memoized since it depends on nothing character- or track-specific. */
    private function rewardQuantityPlan(): array
    {
        if ($this->rewardQuantityPlan !== null) {
            return $this->rewardQuantityPlan;
        }

        $materialGroups = [];
        $materialRarityByGroup = [];
        $packGroups = [];
        $barGroups = [];

        for ($tier = 1; $tier <= self::TOTAL_TIERS; $tier++) {
            if ($tier % 5 !== 0) {
                $profession = ($tier - 1) % 3;
                $rarityIdx = $this->materialRarityIndex($tier);
                $key = "{$profession}-{$rarityIdx}";
                $materialGroups[$key][] = $tier;
                $materialRarityByGroup[$key] = $rarityIdx;
            }

            if ($tier % 5 === 0) {
                $packUnlocked = match (true) {
                    $tier < 20 => 1,
                    $tier < 40 => 2,
                    $tier < 60 => 3,
                    $tier < 80 => 4,
                    default => 5,
                };
                $packGroups[$this->weightedRarityIndex($tier, $packUnlocked, 4)][] = $tier;
            }

            if ($tier % 10 === 0) {
                $barGroups[self::BAR_RARITY_BY_TIER[$tier]][] = $tier;
            }
        }

        $plan = ['material' => [], 'pack' => [], 'bar' => []];

        foreach ($materialGroups as $key => $tiers) {
            $halfTotal = intdiv(self::MATERIAL_TOTAL_BY_RARITY[$materialRarityByGroup[$key]], 2);
            $plan['material'] += $this->distributeEvenly($tiers, $halfTotal);
        }
        foreach ($packGroups as $rarityIdx => $tiers) {
            $halfTotal = intdiv(self::PACK_TOTAL_BY_RARITY[$rarityIdx], 2);
            $plan['pack'] += $this->distributeEvenly($tiers, $halfTotal);
        }
        foreach ($barGroups as $rarityIdx => $tiers) {
            $halfTotal = intdiv(self::BAR_TOTAL_BY_RARITY[$rarityIdx], 2);
            $plan['bar'] += $this->distributeEvenly($tiers, $halfTotal);
        }

        return $this->rewardQuantityPlan = $plan;
    }

    /** Which of the 5 material rarity bands (common/rare/epic/legendary/mythic) this tier's raw-material
     * reward should draw from — shared by rawMaterialKeyForTier (picks the item) and rewardQuantityPlan
     * (groups tiers by this + profession to size each drop), so everything always agrees on which rarity a
     * given tier landed on. The un-ranked starter material (Stone/Wood/Herb) is index 0, then the level
     * 8/20/35/50 unlocks. Full window (no truncation) — every band stays in rotation at every tier, so a
     * tier-99 claim can still roll the plain starter material same as a tier-1 claim can (rarely) roll the
     * mythic one. */
    private function materialRarityIndex(int $tier): int
    {
        $unlocked = match (true) {
            $tier < 10 => 1,
            $tier < 20 => 2,
            $tier < 40 => 3,
            $tier < 70 => 4,
            default => 5,
        };

        return $this->weightedRarityIndex($tier, $unlocked, $unlocked);
    }

    /** Rotates between the three raw gathering materials (ore/wood/herb) at the given rarity index — a
     * tier-90 reward is usually mythril/moonwood/phoenix bloom but still regularly turns up every rarity
     * below it too, all the way down to the plain starter material, instead of lower rarities disappearing
     * entirely once a higher tier unlocks (see materialRarityIndex/weightedRarityIndex). */
    private function rawMaterialKeyForTier(int $tier, int $rarityIdx): string
    {
        return match (($tier - 1) % 3) {
            0 => ['stone', 'iron_ore', 'silver_ore', 'gold_ore', 'mythril_ore'][$rarityIdx],
            1 => ['wood', 'oak_wood', 'ironwood', 'elderwood', 'moonwood'][$rarityIdx],
            default => ['herb', 'sage_leaf', 'moonpetal', 'sunroot', 'phoenix_bloom'][$rarityIdx],
        };
    }

    /** Picks which rarity band a tier's material/pack/bar reward should use, out of the $unlockedCount
     * bands unlocked so far — weighted toward the newest (highest) one so late tiers still feel like late
     * tiers, but not exclusively so. Only considers the top $window bands (default 3), so once deep enough
     * that a band would be more than $window steps behind the newest, it drops out of rotation entirely —
     * that's deliberate: the old flat cutoff dropped every lower rarity to zero the instant a new one
     * unlocked (no more epic materials past tier 70, no more rare packs past tier 80), and a bottomless mix
     * would eventually let a max-tier reward roll all the way back down to common. This keeps a rolling
     * "recent" window instead: within it, the top band fills half the cycle and every other band in the
     * window gets one slot each. E.g. window=3 with 4 bands unlocked cycles through [3,3,3,2,1] — mostly the
     * top rarity, the next two down still turn up 1-in-5 each, and the oldest (common) has aged out. */
    private function weightedRarityIndex(int $tier, int $unlockedCount, int $window = 3): int
    {
        $window = min($window, $unlockedCount);
        if ($window <= 1) {
            return $unlockedCount - 1;
        }

        $top = $unlockedCount - 1;
        $bottom = $unlockedCount - $window;
        $cycle = array_merge(
            array_fill(0, $window, $top),
            range($top - 1, $bottom, -1)
        );

        return $cycle[($tier - 1) % count($cycle)];
    }

    /** Resolves an item definition to a real Item — 'class_gear' entries depend on the character's base_class.
     * Restricted to 'common' rarity so this always lands on the basic craftable class item (e.g. Iron Sword),
     * on BOTH tracks — never one of the same class's gem-shop rare+ pieces, which carry real stat bonuses
     * and would make Premium a straight power purchase instead of a cosmetic/convenience one. */
    private function resolveItem(array $itemDef, Character $character): ?Item
    {
        return isset($itemDef['class_gear'])
            ? Item::where('class_key', $character->base_class)->where('type', $itemDef['class_gear'])->where('rarity', 'common')->first()
            : Item::where('key', $itemDef['key'])->first();
    }

    /** Premium-exclusive cosmetic def for this tier, if any — never present on the free track. */
    private function cosmeticDefForTier(int $tier, bool $premium): ?array
    {
        return $premium ? (self::PREMIUM_COSMETIC_TIERS[$tier] ?? null) : null;
    }

    private function cosmeticKey(array $def): string
    {
        return "battlepass_{$def['key_suffix']}_".self::SEASON;
    }

    /** Reward-tile glyph for a cosmetic type — covers every type PREMIUM_COSMETIC_TIERS actually grants. */
    private function cosmeticGlyph(string $type): string
    {
        return match ($type) {
            'title' => '🏷',
            'frame' => '🖼',
            default => '🎌',
        };
    }

    /** Gold/gems for a tier/track, before item rewards. Gold is a linear ramp sized so the PREMIUM track's
     * total across all 100 tiers is exactly 1,000,000 — tier 100 pays out far more than tier 1 ("gradient
     * slope to the higher tiers"), rather than a flat amount every tier. Free gold is always exactly half,
     * so free+premium together comes to exactly 1.5x the premium total. Gems are NOT spread across the
     * season at all — tier 100 alone grants a lump 1,000 gems on Premium (500 on Free), exactly what
     * Premium itself costs in gems (see BattlePassController::PREMIUM_GEM_COST), so reaching tier 100 pays
     * the pass back in full rather than trickling a partial refund out over the whole season. */
    private function currencyForTier(int $tier, bool $premium): array
    {
        $premiumGold = 200 * $tier - 100; // sum_{t=1}^{100}(200t-100) = 1,000,000
        $gold = $premium ? $premiumGold : intdiv($premiumGold, 2);

        $gems = 0;
        if ($tier === self::TOTAL_TIERS) {
            $gems = $premium ? 1000 : 500;
        }

        return [$gold, $gems];
    }

    /** What a tier's reward looks like — used both to preview the whole track (with item names resolved for
     * this character) and to actually grant on claim. Cosmetic rewards are folded into the same 'items'
     * shape (name/glyph/qty/category) so the frontend needs no special-casing to display them. 'category'
     * (material/pack/bar/gear/cosmetic) lets the reward-track tile pick a sensible single item to headline
     * when a tier grants more than one — see BattlePassPage.vue's primaryItem(). */
    public function rewardForTier(int $tier, bool $premium, Character $character): array
    {
        [$gold, $gems] = $this->currencyForTier($tier, $premium);

        $items = collect($this->itemDefsForTier($tier, $premium))
            ->map(function (array $itemDef) use ($character) {
                $item = $this->resolveItem($itemDef, $character);

                return $item ? ['name' => $item->name, 'glyph' => $item->glyph, 'qty' => $itemDef['qty'], 'category' => $itemDef['category']] : null;
            })
            ->filter()
            ->values()
            ->all();

        $cosmeticDef = $this->cosmeticDefForTier($tier, $premium);
        if ($cosmeticDef) {
            $items[] = ['name' => $cosmeticDef['name'], 'glyph' => $this->cosmeticGlyph($cosmeticDef['type']), 'qty' => 1, 'category' => 'cosmetic'];
        }

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
            $character->user->increment('gems', $gems);
            GemLedger::log($character->user, $gems, "battlepass_claim:tier{$tier}_{$track}", $character);
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

            $grantedItems[] = ['name' => $item->name, 'glyph' => $item->glyph, 'qty' => $qty, 'category' => $itemDef['category']];
        }

        $cosmeticDef = $this->cosmeticDefForTier($tier, $premium);
        if ($cosmeticDef) {
            $cosmetic = Cosmetic::firstOrCreate(
                ['key' => $this->cosmeticKey($cosmeticDef)],
                [
                    'type' => $cosmeticDef['type'], 'name' => $cosmeticDef['name'], 'value' => $cosmeticDef['value'], 'rarity' => $cosmeticDef['rarity'],
                    'category' => 'battle_pass', 'enabled' => true,
                    // Without this, CosmeticController::unlock() has no unlock_quest_key/unlock_event to
                    // block on and (since cost_gems is never set here, so it's 0) would let anyone
                    // "buy" this for free instead of only getting it by actually reaching this tier.
                    'unlock_event' => "battle_pass_tier_{$tier}",
                ]
            );
            CharacterCosmetic::firstOrCreate(
                ['character_id' => $character->id, 'cosmetic_id' => $cosmetic->id],
                ['unlocked_at' => now()]
            );
            $grantedItems[] = ['name' => $cosmeticDef['name'], 'glyph' => $this->cosmeticGlyph($cosmeticDef['type']), 'qty' => 1, 'category' => 'cosmetic'];
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
