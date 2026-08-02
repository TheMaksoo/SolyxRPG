<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\LeaderboardHallOfFame;
use App\Models\LeaderboardSeason;
use App\Models\LeaderboardSnapshot;
use App\Models\PvpRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeaderboardService
{
    public function __construct(private BattlePassService $battlePass) {}

    /** Relations every leaderboard row needs for its cosmetic flex (name color/title/banner/icon/VIP) —
     * identical field set the PvP Arena's own mini-leaderboard uses (see PvpController::index). */
    private const COSMETIC_RELATIONS = ['user', 'activeTitle', 'activeColor', 'activeBanner', 'activeIcon'];

    /** How many rows a board shows before falling back to "your position" below the cut. */
    private const LIMIT = 100;

    /** Season-end reward tiers, ranked by PvP Trophies. Gold/gems go to every rewarded rank; the title
     * cosmetic is champion-only, the banner cosmetic covers the rest of the top 10 — matching the only
     * cosmetic types this game actually has (title/color/banner/icon), no invented reward items. */
    public const REWARD_TIERS = [
        ['from' => 1, 'to' => 1, 'gold' => 20000, 'gems' => 500, 'title' => true, 'banner' => false, 'label' => '#1'],
        ['from' => 2, 'to' => 3, 'gold' => 8000, 'gems' => 200, 'title' => false, 'banner' => true, 'label' => '#2–3'],
        ['from' => 4, 'to' => 10, 'gold' => 4000, 'gems' => 100, 'title' => false, 'banner' => true, 'label' => '#4–10'],
        ['from' => 11, 'to' => 100, 'gold' => 1200, 'gems' => 25, 'title' => false, 'banner' => false, 'label' => '#11–100'],
    ];

    /** category key => board config. 'column' categories are a direct characters-table column, sorted in
     * SQL. 'dungeon_clears'/'companions_collected'/'companion_ranks' are hasMany aggregates (withCount/
     * withSum), also sorted in SQL. 'power' and 'trophies' need PHP-side computation (effectiveStats(),
     * the PvP percentile tier) that can't be expressed as a plain column, so those two — plus any category
     * viewed through a non-'all' range window, which needs a per-character baseline diff — fall back to
     * loading every character and sorting in memory. 'battle_pass_points' is PHP-side too (its value lives
     * on the battle_passes table, not characters, and BattlePassService::pointsEarnedSoFar() already does
     * the tier→points math).
     *
     * Whether a windowed range (season/weekly/daily) shows a GAINED delta or the CURRENT live value comes
     * down to one thing: does this stat actually get reset? Trophies is the only one that does (see
     * PvpSeasonReset), so it's the only category that always shows CURRENT regardless of range — a
     * "rating gained this week" reading is meaningless against a number that just got reset mid-week.
     * Every other category, including Power and Battle Pass Points (neither of which ever resets, they
     * just aren't literally "banked" anywhere), shows a real GAINED-this-period delta like Gold always has
     * (see memoryBoard's $isPureLive). 'guild_power' is a separate, guild-based board entirely (see
     * guildPowerBoard()), live-only, no range/scope support at all — it's a guild-wide gauge, not a
     * per-character stat with a snapshot history. */
    public const CATEGORIES = [
        'power' => ['label' => 'Power', 'glyph' => '💪', 'suffix' => '', 'type' => 'power', 'group' => 'COMBAT'],
        'level' => ['label' => 'Level', 'glyph' => '⭐', 'suffix' => '', 'type' => 'column', 'column' => 'level', 'group' => 'COMBAT'],
        'trophies' => ['label' => 'Trophies', 'glyph' => '🏆', 'suffix' => '', 'type' => 'trophies', 'group' => 'COMBAT'],
        'monsters_slain' => ['label' => 'Monsters Slain', 'glyph' => '⚔', 'suffix' => '', 'type' => 'column', 'column' => 'battles_won', 'group' => 'COMBAT'],
        'bosses_slain' => ['label' => 'Boss Slayer', 'glyph' => '👹', 'suffix' => '', 'type' => 'column', 'column' => 'bosses_slain', 'group' => 'COMBAT'],
        'deaths' => ['label' => 'Deaths', 'glyph' => '💀', 'suffix' => '', 'type' => 'column', 'column' => 'battles_lost', 'group' => 'COMBAT'],
        'gold' => ['label' => 'Richest', 'glyph' => '🪙', 'suffix' => 'g', 'type' => 'column', 'column' => 'gold', 'group' => 'WEALTH'],
        'gems' => ['label' => 'Gem Hoarder', 'glyph' => '💎', 'suffix' => '', 'type' => 'column', 'column' => 'gems', 'group' => 'WEALTH'],
        'quests_completed' => ['label' => 'Quest Completionist', 'glyph' => '📜', 'suffix' => '', 'type' => 'column', 'column' => 'quests_completed', 'group' => 'WEALTH'],
        'battle_pass_points' => ['label' => 'Battle Pass Points', 'glyph' => '🎫', 'suffix' => ' pts', 'type' => 'battle_pass_points', 'group' => 'WEALTH'],
        'times_mined' => ['label' => 'Master Miner', 'glyph' => '⛏', 'suffix' => '', 'type' => 'column', 'column' => 'times_mined', 'group' => 'PROFESSIONS'],
        'times_chopped' => ['label' => 'Lumberjack', 'glyph' => '🪓', 'suffix' => '', 'type' => 'column', 'column' => 'times_chopped', 'group' => 'PROFESSIONS'],
        'times_smelted' => ['label' => 'Forge Master', 'glyph' => '🔥', 'suffix' => '', 'type' => 'column', 'column' => 'times_smelted', 'group' => 'PROFESSIONS'],
        'times_foraged' => ['label' => 'Herbalist', 'glyph' => '🌿', 'suffix' => '', 'type' => 'column', 'column' => 'times_foraged', 'group' => 'PROFESSIONS'],
        'times_crafted' => ['label' => 'Master Crafter', 'glyph' => '🔨', 'suffix' => '', 'type' => 'column', 'column' => 'times_crafted', 'group' => 'PROFESSIONS'],
        'dungeon_clears' => ['label' => 'Dungeon Delver', 'glyph' => '🗺', 'suffix' => '', 'type' => 'dungeon_clears', 'group' => 'EXPLORATION'],
        'companions_collected' => ['label' => 'Companion Collector', 'glyph' => '🐾', 'suffix' => '', 'type' => 'companions_collected', 'group' => 'EXPLORATION'],
        'companion_ranks' => ['label' => 'Companion Master', 'glyph' => '🎖', 'suffix' => '', 'type' => 'companion_ranks', 'group' => 'EXPLORATION'],
        'guild_power' => ['label' => 'Guild Power', 'glyph' => '🛡', 'suffix' => '', 'type' => 'guild_power', 'group' => 'EXPLORATION'],
    ];

    /** Every category tracked by the nightly snapshot job — everything except guild_power, which has no
     * range/rank-history support (see class docblock). */
    public function trackedCategories(): array
    {
        return array_values(array_diff(array_keys(self::CATEGORIES), ['guild_power']));
    }

    /** Main entry point: rows + your-own-rank fallback for one category/range/scope combination. */
    public function board(string $category, ?Character $character, string $range = 'all', string $scope = 'global'): array
    {
        $config = self::CATEGORIES[$category] ?? self::CATEGORIES['power'];

        if ($category === 'guild_power') {
            return $this->guildPowerBoard($character);
        }

        $characterIds = $this->scopeCharacterIds($character, $scope);
        if ($characterIds === []) {
            return ['rows' => [], 'my_rank' => null, 'scope_empty_reason' => $scope === 'guild' ? 'not_in_guild' : 'no_friends'];
        }

        $usesMemory = in_array($config['type'], ['power', 'trophies', 'battle_pass_points'], true) || $range !== 'all';

        return $usesMemory
            ? $this->memoryBoard($category, $config, $character, $range, $characterIds)
            : $this->sqlBoard($config, $character, $characterIds);
    }

    /** Which character ids a scope restricts the board to — null means no restriction (global), [] means
     * "nothing to show" (not in a guild / no friends yet), otherwise the exact id list. */
    private function scopeCharacterIds(?Character $character, string $scope): ?array
    {
        if ($scope === 'guild') {
            $guildId = $character?->guildMembership?->guild_id;

            return $guildId ? \App\Models\GuildMember::where('guild_id', $guildId)->pluck('character_id')->all() : [];
        }

        if ($scope === 'friends') {
            if (! $character) {
                return [];
            }
            $ids = $character->friends()->pluck('id')->all();
            $ids[] = $character->id;

            return $ids;
        }

        return null;
    }

    /** Every category whose value is either a plain characters-table column or a hasMany aggregate — the
     * database does the sorting, so this never loads more than LIMIT+1 characters regardless of how many
     * exist in the game. Only used for range='all' (a windowed range needs a per-character baseline diff,
     * handled by memoryBoard instead). */
    private function sqlBoard(array $config, ?Character $character, ?array $characterIds): array
    {
        $query = $this->valueQuery($config)->when($characterIds !== null, fn ($q) => $q->whereIn('characters.id', $characterIds));
        $rows = $query->orderByDesc('value')->limit(self::LIMIT)->get();

        // Always computed (not just when outside the top 100) so the frontend can work out which page
        // "Jump to me" should land on even when the player IS in the top 100, just not the current page.
        $myRank = null;
        if ($character) {
            $myValue = (int) ($this->valueQuery($config)->whereKey($character->id)->first()->value ?? 0);
            $ahead = $this->valueQuery($config)
                ->when($characterIds !== null, fn ($q) => $q->whereIn('characters.id', $characterIds))
                ->having('value', '>', $myValue)->get()->count();
            $above = $this->valueQuery($config)
                ->when($characterIds !== null, fn ($q) => $q->whereIn('characters.id', $characterIds))
                ->having('value', '>', $myValue)->orderBy('value')->first();
            $myRank = $this->rowFor($character, $ahead + 1, $myValue);
            $myRank['points_to_next_rank'] = $above ? max(0, (int) $above->value - $myValue) : 0;
        }

        return [
            'rows' => $rows->values()->map(fn (Character $c, int $i) => $this->rowFor($c, $i + 1, (int) $c->value))->all(),
            'my_rank' => $myRank,
            'scope_empty_reason' => null,
        ];
    }

    /** Builds the base character query for a 'column'/'dungeon_clears'/'companions_collected'/
     * 'companion_ranks' category, selecting a uniform 'value' column so callers can sort/compare on it
     * without caring which kind of aggregate produced it. Always live/all-time. */
    private function valueQuery(array $config): Builder
    {
        $query = Character::query()->with(self::COSMETIC_RELATIONS);

        return match ($config['type']) {
            'column' => $query->select('characters.*')->selectRaw("{$config['column']} as value"),
            'dungeon_clears' => $query->withCount(['dungeonRuns as value' => fn ($q) => $q->where('status', 'completed')]),
            'companions_collected' => $query->withCount('pets as value'),
            'companion_ranks' => $query->withSum('pets as value', 'rank'),
        };
    }

    /** 'power', 'trophies' and 'battle_pass_points' always route here (per-character PHP computation that
     * can't be pushed into SQL). Every other category routes here only when range != 'all', since a
     * windowed value (current minus a snapshot baseline) needs a per-character lookup that's simplest done
     * in memory at this game's scale — the same "load everything, sort in PHP" cost the whole endpoint used
     * to pay for every category before last session's SQL-pushdown pass. Power and Battle Pass Points
     * support windowed ranges same as any other cumulative stat (power gained / points gained this week is
     * a meaningful board) — Trophies doesn't, since it's the one stat that actually gets reset (see
     * $isPureLive below), so it always shows the live, current rating regardless of range. */
    private function memoryBoard(string $category, array $config, ?Character $character, string $range, ?array $characterIds): array
    {
        // Trophies is the ONLY category that actually resets (PvpSeasonReset soft-resets it monthly), so
        // it's the only one where a windowed range shows the CURRENT rating rather than a GAINED delta —
        // computing "rating gained this week" against a stat that just got reset mid-week wouldn't mean
        // what it looks like it means. Every other category (including Power and Battle Pass Points, which
        // never reset) shows a real gained-this-period delta like Gold/quests_completed always have.
        $isPureLive = $category === 'trophies';
        $rangeApplies = $range !== 'all' && ! $isPureLive;
        // Trophies always shows the live, absolute rating now (see $isPureLive above), so its tier badge
        // (Bronze/Diamond/etc.) is always meaningful — no longer suppressed for non-'all' ranges.
        $showTrophyTier = $category === 'trophies';

        $allRatings = $showTrophyTier ? PvpRecord::pluck('rating')->all() : [];
        $baselines = $rangeApplies ? $this->baselinesFor($category, $range) : collect();

        $relations = [
            ...self::COSMETIC_RELATIONS, 'pvpRecord',
            ...($category === 'power' ? ['attributes_', 'inventory.item', 'pets.pet'] : []),
            ...($config['type'] === 'dungeon_clears' ? ['dungeonRuns'] : []),
            ...(in_array($config['type'], ['companions_collected', 'companion_ranks'], true) ? ['pets'] : []),
            ...($category === 'battle_pass_points' ? ['battlePasses'] : []),
        ];

        $all = Character::with($relations)
            ->when($characterIds !== null, fn ($q) => $q->whereIn('id', $characterIds))
            ->get()
            ->map(function (Character $c) use ($category, $config, $rangeApplies, $baselines) {
                $live = match (true) {
                    $category === 'power' => $c->effectiveStats()['power'],
                    $category === 'trophies' => $c->pvpRecord->rating ?? 1000,
                    $category === 'battle_pass_points' => $this->battlePassPointsFor($c),
                    $config['type'] === 'column' => (int) $c->{$config['column']},
                    $config['type'] === 'dungeon_clears' => $c->dungeonRuns->where('status', 'completed')->count(),
                    $config['type'] === 'companions_collected' => $c->pets->count(),
                    $config['type'] === 'companion_ranks' => (int) $c->pets->sum('rank'),
                    default => 0,
                };
                $baseline = $rangeApplies ? (int) ($baselines[$c->id] ?? 0) : 0;

                return ['character' => $c, 'value' => $live - $baseline];
            })
            ->sortByDesc('value')
            ->values();

        $ranked = $all->take(self::LIMIT)->map(fn ($row, $i) => $this->rowFor(
            $row['character'], $i + 1, $row['value'],
            $showTrophyTier ? PvpRecord::hybridRank($row['value'], $allRatings) : null
        ));

        // Always computed (not just when outside the top 100) so the frontend can work out which page
        // "Jump to me" should land on even when the player IS in the top 100, just not the current page.
        $myRank = null;
        if ($character) {
            $myIndex = $all->search(fn ($row) => $row['character']->id === $character->id);
            if ($myIndex !== false) {
                $row = $all[$myIndex];
                $myRank = $this->rowFor(
                    $row['character'], $myIndex + 1, $row['value'],
                    $showTrophyTier ? PvpRecord::hybridRank($row['value'], $allRatings) : null
                );
                $myRank['points_to_next_rank'] = $myIndex > 0 ? max(0, $all[$myIndex - 1]['value'] - $row['value']) : 0;
            }
        }

        return ['rows' => $ranked->all(), 'my_rank' => $myRank, 'scope_empty_reason' => null];
    }

    /** character_id => baseline_value for the given category's current period boundary (today's date /
     * this Monday's date / the active season's id). */
    private function baselinesFor(string $category, string $range): Collection
    {
        return LeaderboardSnapshot::where('category', $category)
            ->where('period', $range)
            ->where('period_key', $this->periodKeyFor($range))
            ->pluck('baseline_value', 'character_id');
    }

    private function periodKeyFor(string $range): string
    {
        return match ($range) {
            'daily' => now()->toDateString(),
            'weekly' => now()->startOfWeek()->toDateString(),
            'season' => (string) $this->currentSeason()->id,
            default => '',
        };
    }

    private function rowFor(Character $c, int $rank, int $value, ?array $trophyRank = null): array
    {
        return [
            'rank' => $rank,
            'character_id' => $c->id,
            'name' => $c->name,
            'level' => $c->level,
            'base_class' => $c->base_class,
            'guild_name' => $c->guildMembership?->guild?->name,
            'guild_tag' => $c->guildMembership?->guild?->tag,
            'value' => $value,
            'trophy_rank' => $trophyRank,
            'vip_tier' => $c->user?->hasActiveVip() ? $c->user->vip_tier : 'none',
            'title' => $c->activeTitle?->value,
            'name_color' => $c->activeColor?->value,
            'banner' => $c->activeBanner?->value,
            'icon' => $c->activeIcon?->value,
        ];
    }

    /** Guild-based board: combined member Power per guild (see Guild::power()). Live-only — no range or
     * scope support, since a guild's power is a live gauge, not a per-character cumulative counter. */
    public function guildPowerBoard(?Character $character): array
    {
        $guilds = Guild::with(['members.character.attributes_', 'members.character.inventory.item', 'members.character.pets.pet'])
            ->get()
            ->map(fn (Guild $g) => ['guild' => $g, 'value' => $g->power()])
            ->sortByDesc('value')
            ->values();

        $rows = $guilds->take(self::LIMIT)->map(fn ($row, $i) => $this->guildRowFor($row['guild'], $i + 1, $row['value']));

        $myRank = null;
        $myGuildId = $character?->guildMembership?->guild_id;
        if ($myGuildId) {
            $myIndex = $guilds->search(fn ($row) => $row['guild']->id === $myGuildId);
            if ($myIndex !== false && $myIndex >= self::LIMIT) {
                $row = $guilds[$myIndex];
                $myRank = $this->guildRowFor($row['guild'], $myIndex + 1, $row['value']);
            }
        }

        return ['rows' => $rows->all(), 'my_rank' => $myRank, 'scope_empty_reason' => null];
    }

    private function guildRowFor(Guild $g, int $rank, int $value): array
    {
        return [
            'rank' => $rank,
            'character_id' => null,
            'name' => $g->name,
            'level' => $g->level,
            'base_class' => "{$g->tag} · {$g->members->count()} members",
            'guild_name' => null,
            'guild_tag' => null,
            'value' => $value,
            'trophy_rank' => null,
            'vip_tier' => 'none',
            'title' => null,
            'name_color' => null,
            'banner' => null,
            'icon' => '🛡',
        ];
    }

    /** Single-category rank lookup — for callers (e.g. the Profile page's Rankings card) that only need a
     * handful of categories rather than the full 18-category sweep myRanksFor() does. Null for an unknown
     * category key. */
    public function rankFor(string $category, Character $character): ?int
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (! $config) {
            return null;
        }

        return $category === 'guild_power'
            ? $this->myGuildPowerRank($character)
            : $this->myRankFor($category, $config, $character);
    }

    /** Bulk "my rank in every category" for the sidebar's per-category pills — all-time, global scope
     * only (a windowed/scoped rank per category would mean up to 18 extra queries per combination, not
     * worth it for a glance pill). */
    public function myRanksFor(Character $character): array
    {
        $ranks = [];
        foreach (self::CATEGORIES as $key => $config) {
            $ranks[$key] = $key === 'guild_power'
                ? $this->myGuildPowerRank($character)
                : $this->myRankFor($key, $config, $character);
        }

        return $ranks;
    }

    private function myRankFor(string $category, array $config, Character $character): int
    {
        if (in_array($config['type'], ['power', 'trophies', 'battle_pass_points'], true)) {
            $target = $this->valueFor($character, $category, $config);
            $relations = match ($category) {
                'power' => ['attributes_', 'inventory.item', 'pets.pet'],
                'battle_pass_points' => ['battlePasses'],
                default => ['pvpRecord'],
            };

            return Character::with($relations)
                ->get()
                ->filter(fn (Character $c) => $this->valueFor($c, $category, $config) > $target)
                ->count() + 1;
        }

        $myValue = (int) ($this->valueQuery($config)->whereKey($character->id)->first()->value ?? 0);

        return $this->valueQuery($config)->having('value', '>', $myValue)->get()->count() + 1;
    }

    private function myGuildPowerRank(Character $character): ?int
    {
        $guildId = $character->guildMembership?->guild_id;
        if (! $guildId) {
            return null;
        }

        $guilds = Guild::with(['members.character.attributes_', 'members.character.inventory.item', 'members.character.pets.pet'])->get();
        $mine = $guilds->firstWhere('id', $guildId);
        if (! $mine) {
            return null;
        }
        $target = $mine->power();

        return $guilds->filter(fn (Guild $g) => $g->power() > $target)->count() + 1;
    }

    /** A single character's live value for a category — used by myRankFor() and rivals(), where only one
     * or a handful of characters need computing rather than the whole table. */
    private function valueFor(Character $c, string $category, array $config): int
    {
        return match (true) {
            $category === 'power' => (int) $c->effectiveStats()['power'],
            $category === 'trophies' => (int) ($c->pvpRecord->rating ?? 1000),
            $category === 'battle_pass_points' => $this->battlePassPointsFor($c),
            $config['type'] === 'column' => (int) $c->{$config['column']},
            $config['type'] === 'dungeon_clears' => $c->dungeonRuns()->where('status', 'completed')->count(),
            $config['type'] === 'companions_collected' => $c->pets()->count(),
            $config['type'] === 'companion_ranks' => (int) $c->pets()->sum('rank'),
            default => 0,
        };
    }

    /** Total Battle Pass points a character has earned this pass — every completed tier's cost plus
     * whatever's banked toward the next one (see BattlePassService::pointsEarnedSoFar). Reads the existing
     * battle_passes row rather than passFor()'s firstOrCreate, so viewing the leaderboard never creates a
     * row for a character who's never touched the Battle Pass — they just show 0. */
    private function battlePassPointsFor(Character $c): int
    {
        // Property access lazy-loads when not already eager-loaded via with('battlePasses'), so this is
        // safe to call from both the bulk board methods and the single-character valueFor()/rivals() path.
        $pass = $c->battlePasses->firstWhere('season', BattlePassService::SEASON);

        return $pass ? $this->battlePass->pointsEarnedSoFar($pass) : 0;
    }

    /** Every character in the game, category value, ranked, no limit — used by the nightly snapshot job
     * and the season rollover job, neither of which can settle for the top-100 cutoff the live board uses. */
    public function fullRanking(string $category): Collection
    {
        $config = self::CATEGORIES[$category];

        if (in_array($config['type'], ['power', 'trophies', 'battle_pass_points'], true)) {
            $relations = match ($category) {
                'power' => ['attributes_', 'inventory.item', 'pets.pet', 'pvpRecord'],
                'battle_pass_points' => ['battlePasses'],
                default => ['pvpRecord'],
            };

            return Character::with($relations)
                ->get()
                ->map(fn (Character $c) => [
                    'character_id' => $c->id,
                    'value' => match ($category) {
                        'trophies' => $c->pvpRecord->rating ?? 1000,
                        'battle_pass_points' => $this->battlePassPointsFor($c),
                        default => $c->effectiveStats()['power'],
                    },
                ])
                ->sortByDesc('value')
                ->values();
        }

        return $this->valueQuery($config)->orderByDesc('value')->get()
            ->map(fn (Character $c) => ['character_id' => $c->id, 'value' => (int) $c->value])
            ->values();
    }

    /** Writes (or refreshes, idempotently) a full-table baseline+rank snapshot for every tracked category
     * at the given period boundary — called nightly for 'daily'/'weekly' and once per season start. */
    public function snapshotAllCategories(string $period, string $periodKey): void
    {
        foreach ($this->trackedCategories() as $category) {
            $rows = $this->fullRanking($category)
                ->map(fn ($r, $i) => [
                    'category' => $category,
                    'period' => $period,
                    'period_key' => $periodKey,
                    'character_id' => $r['character_id'],
                    'baseline_value' => $r['value'],
                    'rank_at_boundary' => $i + 1,
                    'created_at' => now(),
                ])
                ->all();

            if ($rows) {
                LeaderboardSnapshot::upsert($rows, ['category', 'period', 'period_key', 'character_id'], ['baseline_value', 'rank_at_boundary']);
            }
        }
    }

    /** The active season, lazily bootstrapping "Season 1" if none exists yet — mirrors the lazy self-heal
     * pattern GuildWarController::resetPointsIfNeeded already uses for guild war points, so this ships
     * without a manual seeding step. Aligned to the real calendar month (1st through the last day),
     * not a rolling 30 days from whenever this first ran — so "Season N" always matches the actual month
     * whether that's 28, 29, 30, or 31 days. */
    public function currentSeason(): LeaderboardSeason
    {
        $active = LeaderboardSeason::where('is_active', true)->first();
        if ($active) {
            return $active;
        }

        return LeaderboardSeason::create([
            'season_number' => 1,
            'name' => 'Season 1',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->endOfMonth(),
            'is_active' => true,
        ]);
    }

    /** Human-readable season-end reward description for the given category — some categories are worth
     * meaningfully more than others, so this exists specifically so the "Season Rewards" card never lies
     * about what a category actually pays out. Trophies is the only category with a real currency payout
     * (see REWARD_TIERS, still the source of truth LeaderboardSeasonRollover::grantReward reads from);
     * every other category (see LeaderboardSeasonRollover::grantCategoryTitles) is title-only — rank 1
     * gets "Champion of X", ranks 2-10 get "Top 10 in X", no gold or gems. */
    public function rewardTiersFor(string $category): array
    {
        if ($category === 'trophies') {
            return collect(self::REWARD_TIERS)->map(function ($t) {
                $cosmeticPart = match (true) {
                    $t['title'] => ', "Season Champion" title',
                    $t['banner'] => ', Top 10 banner',
                    default => '',
                };

                return [
                    'label' => $t['label'],
                    'reward' => trim(sprintf(
                        '%s gold%s%s',
                        number_format($t['gold']),
                        $t['gems'] > 0 ? ', '.number_format($t['gems']).' gems' : '',
                        $cosmeticPart
                    )),
                ];
            })->all();
        }

        $label = self::CATEGORIES[$category]['label'] ?? $category;

        return [
            ['label' => '#1', 'reward' => "\"Champion of {$label}\" title"],
            ['label' => '#2–10', 'reward' => "\"Top 10 in {$label}\" title"],
        ];
    }

    /** Past season champions (rank 1 only), most recent season first — powers the "Hall of Fame" card. */
    public function hallOfFame(int $limit = 10): array
    {
        return LeaderboardHallOfFame::where('rank', 1)
            ->with('season')
            ->orderByDesc('season_id')
            ->limit($limit)
            ->get()
            ->map(fn (LeaderboardHallOfFame $h) => ['season' => $h->season?->name, 'name' => $h->character_name])
            ->all();
    }

    /** Every real-money spender, ranked by lifetime_spend_cents — unlike FounderPackController's Hall
     * of Founders list this isn't limited to Founder Pack buyers, it's every account with any spend at
     * all, and (same as that list) unbounded rather than capped to a "top N". The amount itself never
     * leaves this method — only rank + name — same "who, in what order, nothing more" treatment. */
    public function supporters(): array
    {
        return User::where('lifetime_spend_cents', '>', 0)
            ->orderByDesc('lifetime_spend_cents')
            ->with('character:id,user_id,name')
            ->get()
            ->map(fn (User $u) => ['name' => $u->character?->name ?? $u->name])
            ->all();
    }

    /** Each accepted friend's value/gap in the given category vs the requesting character — powers the
     * "Rivals" (Friends) rail card. Friends is a small list, so per-friend live queries (via valueFor())
     * are simplest rather than another bulk board query. */
    public function rivals(Character $character, string $category): array
    {
        $friends = $character->friends();
        if ($friends->isEmpty()) {
            return [];
        }

        $config = self::CATEGORIES[$category] ?? self::CATEGORIES['power'];
        $myValue = $this->valueFor($character, $category, $config);

        return $friends
            ->map(fn (Character $f) => [
                'character_id' => $f->id,
                'name' => $f->name,
                'gap' => $myValue - $this->valueFor($f, $category, $config),
            ])
            ->sortBy(fn ($r) => abs($r['gap']))
            ->values()
            ->all();
    }
}
