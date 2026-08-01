<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Character;
use App\Models\CharacterAchievement;
use App\Models\CharacterCosmetic;
use App\Models\CharacterPet;
use App\Models\CraftingJob;
use App\Models\DungeonRun;
use App\Models\GemLedger;
use App\Models\GuildMember;
use App\Models\MarketListing;
use App\Models\PvpMatch;
use App\Services\BattlePassService;
use App\Services\LeaderboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /** Free-form self-description tags shown on the hero — purely a "how to find/group with me" label a
     * player picks for themselves, not tied to any stat or system (same idea as a bio). */
    public const PLAYSTYLE_TAGS = [
        'Solo-leaning', 'Duels welcome', 'Raid lead', 'Completionist', 'Casual pace',
        'Night player', 'Early bird', 'PvP focused', 'Trader', 'Helps newcomers',
    ];

    public const RENAME_COST_GEMS = 1000;

    public function __construct(private LeaderboardService $leaderboard, private BattlePassService $battlePass) {}

    public function panels(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $gear = $this->equippedGear($character);

        return response()->json([
            'equipped_gear' => $gear,
            'gear_score' => array_sum(array_column($gear, 'score')),
            'recent_activity' => $this->recentActivity($character),
            'guild_card' => $this->guildCard($character),
            'rankings' => $this->rankings($character),
            'battle_pass_summary' => $this->battlePassSummary($character),
            'pvp_summary' => $this->pvpSummary($character),
            'toughest_foes' => $this->toughestFoes($character),
            'combat_profile' => $this->combatProfile($character),
            'playtime_seconds' => $character->playtime_seconds,
            'privacy' => $character->privacySettingsWithDefaults(),
        ]);
    }

    /** How this character's real combat stats compare to other characters within 5 levels of them —
     * a genuine average of a bounded real sample (capped at 60 peers so this never scans the whole
     * server), not a fabricated "server average" number. Bars share a dynamic ceiling per stat (max of
     * mine/theirs, with headroom) rather than a hardcoded max, since there's no real absolute cap on any
     * of these stats to scale against. */
    private function combatProfile(Character $character): array
    {
        $stats = $character->effectiveStats();
        $lo = max(1, $character->level - 5);
        $hi = $character->level + 5;

        $peers = Character::whereBetween('level', [$lo, $hi])
            ->where('id', '!=', $character->id)
            ->with(['attributes_', 'inventory.item', 'pets.pet', 'skills', 'guildMembership.guild', 'partyMembership.party.members.character', 'user'])
            ->limit(60)
            ->get();

        $rows = [
            ['key' => 'eff_atk', 'label' => 'Attack'],
            ['key' => 'eff_def', 'label' => 'Defense'],
            ['key' => 'eff_hp_max', 'label' => 'HP'],
            ['key' => 'crit_chance', 'label' => 'Crit Chance', 'suffix' => '%'],
            ['key' => 'dodge_chance', 'label' => 'Dodge Chance', 'suffix' => '%'],
        ];

        $peerStats = $peers->map(fn (Character $p) => $p->effectiveStats());

        return [
            'bracket' => "LV {$lo}-{$hi}",
            'sample_size' => $peerStats->count(),
            'rows' => collect($rows)->map(function ($r) use ($stats, $peerStats) {
                $value = $stats[$r['key']];
                $avg = $peerStats->isEmpty() ? $value : round($peerStats->avg(fn ($s) => $s[$r['key']]), 1);
                $ceiling = max($value, $avg, 1) * 1.25;

                return [
                    'label' => $r['label'],
                    'value' => $value,
                    'avg' => $avg,
                    'suffix' => $r['suffix'] ?? '',
                    'pct' => min(100, round($value / $ceiling * 100)),
                    'avg_pct' => min(100, round($avg / $ceiling * 100)),
                ];
            })->all(),
        ];
    }

    /** Real "who beats me the most" data — Battle rows never get deleted on resolution (see
     * CombatService::resolveLoss(), which just flips status to 'lost'), so a per-monster loss count is a
     * genuine query, not a fabricated stat. Same idea for PvpMatch on the duel side. */
    private function toughestFoes(Character $character): array
    {
        $monsterRow = Battle::where('character_id', $character->id)
            ->where('status', 'lost')
            ->whereNotNull('monster_id')
            ->selectRaw('monster_id, count(*) as losses')
            ->groupBy('monster_id')
            ->orderByDesc('losses')
            ->with('monster')
            ->first();

        $rivalRow = PvpMatch::where('character_id', $character->id)
            ->where('result', 'loss')
            ->selectRaw('opponent_id, count(*) as losses')
            ->groupBy('opponent_id')
            ->orderByDesc('losses')
            ->first();
        $rival = $rivalRow ? Character::find($rivalRow->opponent_id) : null;

        return [
            'monster' => $monsterRow?->monster ? [
                'name' => $monsterRow->monster->name,
                'glyph' => $monsterRow->monster->glyph,
                'losses' => $monsterRow->losses,
            ] : null,
            'rival' => $rival ? [
                'name' => $rival->name,
                'losses' => $rivalRow->losses,
            ] : null,
        ];
    }

    /** Real per-day history for one leaderboard category, read straight from the daily snapshot table the
     * `leaderboard:snapshot-daily` cron already writes (see LeaderboardService::snapshotAllCategories) —
     * no separate history table, no simulated trend. A brand-new character (or one before this cron ever
     * ran) will just have 0-1 points; the line only gets richer as more real days accumulate. */
    public function history(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(LeaderboardService::CATEGORIES))],
        ]);
        $category = $data['category'];
        abort_if($category === 'guild_power', 422, 'Guild Power has no per-character history.');

        $points = \App\Models\LeaderboardSnapshot::where('character_id', $character->id)
            ->where('period', 'daily')
            ->where('category', $category)
            ->orderBy('period_key')
            ->get(['period_key', 'baseline_value'])
            ->map(fn ($row) => ['date' => $row->period_key, 'value' => $row->baseline_value])
            ->values()
            ->all();

        return response()->json([
            'category' => $category,
            'label' => LeaderboardService::CATEGORIES[$category]['label'],
            'points' => $points,
            'categories' => collect(LeaderboardService::CATEGORIES)
                ->except('guild_power')
                ->map(fn ($c, $key) => ['key' => $key, 'label' => $c['label'], 'group' => $c['group'], 'glyph' => $c['glyph']])
                ->values()
                ->all(),
        ]);
    }

    /** Updates the two purely-cosmetic self-description fields — bio text and up to 4 playstyle tags —
     * shown on the hero and in Customize. Neither affects gameplay, so validation is just shape/length. */
    public function updateMeta(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'bio' => ['nullable', 'string', 'max:160'],
            'playstyle_tags' => ['nullable', 'array', 'max:4'],
            'playstyle_tags.*' => ['string', Rule::in(self::PLAYSTYLE_TAGS)],
        ]);

        $character->update([
            'bio' => $data['bio'] ?? null,
            'playstyle_tags' => $data['playstyle_tags'] ?? [],
        ]);

        return response()->json([
            'bio' => $character->bio,
            'playstyle_tags' => $character->playstyle_tags,
        ]);
    }

    /** Per-field public-profile visibility toggles (see Character::privacySetting() for the defaults
     * every unset field falls back to, and CharacterController::publicProfile() for where these actually
     * get enforced). 'activity_feed_visibility' and 'open_to_duels' are stored and returned like the
     * rest, but don't gate anything yet — there's no public activity feed or duel-challenge feature to
     * gate on the public profile today, so they're forward-looking preferences for now, not enforced. */
    public function updatePrivacy(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'gear_visible' => ['required', 'boolean'],
            'combat_stats_visible' => ['required', 'boolean'],
            'activity_feed_visibility' => ['required', Rule::in(['public', 'friends', 'hidden'])],
            'playtime_visible' => ['required', 'boolean'],
            'open_to_duels' => ['required', 'boolean'],
        ]);

        $character->update(['privacy_settings' => $data]);

        return response()->json(['privacy' => $character->privacySettingsWithDefaults()]);
    }

    /** Flat gem-cost rename, no free tier or cooldown — same 'max:30' shape CharacterController::store()
     * validates a name against at creation. */
    public function rename(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:30'],
        ]);

        if ($character->user->gems < self::RENAME_COST_GEMS) {
            return response()->json(['message' => 'Not enough gems.'], 422);
        }

        $character->user->decrement('gems', self::RENAME_COST_GEMS);
        GemLedger::log($character->user, -self::RENAME_COST_GEMS, 'character_rename', $character);
        $character->update(['name' => $data['name']]);

        return response()->json(['character' => $character->fresh()]);
    }

    private function pvpSummary(Character $character): array
    {
        $record = $character->pvpRecord;

        return [
            'rating' => $record?->rating ?? 1000,
            'win_streak' => $record?->win_streak ?? 0,
            'wins' => $record?->wins ?? 0,
            'losses' => $record?->losses ?? 0,
        ];
    }

    /** Sets which earned achievements are pinned to the top of the Trophy Case — up to 5, must already be
     * earned (no showing off something you haven't actually done). */
    public function updateShowcase(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'achievement_ids' => ['required', 'array', 'max:5'],
            'achievement_ids.*' => ['integer'],
        ]);

        $earnedIds = CharacterAchievement::where('character_id', $character->id)
            ->whereIn('achievement_id', $data['achievement_ids'])
            ->pluck('achievement_id');

        if ($earnedIds->count() !== count($data['achievement_ids'])) {
            return response()->json(['message' => 'You can only showcase achievements you\'ve actually earned.'], 422);
        }

        $character->update(['showcased_achievement_ids' => $earnedIds->values()->all()]);

        return response()->json(['showcased_achievement_ids' => $character->showcased_achievement_ids]);
    }

    private function equippedGear(Character $character): array
    {
        return $character->inventory()->where('equipped', true)->with('item')->get()
            ->map(fn ($slot) => [
                'name' => $slot->item->name,
                'glyph' => $slot->item->glyph,
                'rarity' => $slot->item->rarity,
                'slot' => ucfirst($slot->item->type),
                'durability_pct' => $slot->durability_max ? (int) round($slot->durability / $slot->durability_max * 100) : null,
                // Real per-item power number — the sum of that item's own stat_json values (atk/def/etc,
                // see ItemSeeder), not a fabricated "item level".
                'score' => (int) array_sum(array_filter($slot->item->stat_json ?? [], 'is_numeric')),
            ])
            ->values()
            ->all();
    }

    /** Merges three real, already-timestamped sources into one feed — there's no dedicated activity-log
     * table in this game, so this reads straight off achievement/duel/cosmetic history rather than
     * fabricating events that never happened. Windowed to the last 7 days like the design calls for; a
     * quiet character (nothing in 7 days) falls back to their latest activity ever, and the response says
     * which window is actually shown so the frontend never has to lie about it. */
    private function recentActivity(Character $character): array
    {
        $since = now()->subDays(7);
        $items = $this->activitySince($character, $since);
        $window = '7 days';

        if ($items->isEmpty()) {
            $items = $this->activitySince($character, null);
            $window = 'all time';
        }

        return [
            'window' => $window,
            'items' => $items->sortByDesc('time')->take(10)->values()->all(),
        ];
    }

    private function activitySince(Character $character, ?\Illuminate\Support\Carbon $since): Collection
    {
        $achievements = CharacterAchievement::where('character_id', $character->id)
            ->whereNotNull('earned_at')
            ->when($since, fn ($q) => $q->where('earned_at', '>=', $since))
            ->with('achievement')
            ->latest('earned_at')
            ->limit(10)
            ->get()
            ->map(fn (CharacterAchievement $row) => [
                'time' => $row->earned_at,
                'text' => "Earned the **{$row->achievement->name}** achievement",
                'kind' => 'achievement',
            ]);

        $duels = PvpMatch::where('character_id', $character->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->with('opponent')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (PvpMatch $row) => [
                'time' => $row->created_at,
                'text' => ($row->result === 'win' ? 'Won a duel against **' : 'Lost a duel against **').($row->opponent?->name ?? 'a rival').'**',
                'kind' => $row->result === 'win' ? 'duel_win' : 'duel_loss',
            ]);

        $cosmetics = CharacterCosmetic::where('character_id', $character->id)
            ->when($since, fn ($q) => $q->where('unlocked_at', '>=', $since))
            ->with('cosmetic')
            ->latest('unlocked_at')
            ->limit(10)
            ->get()
            ->map(fn (CharacterCosmetic $row) => [
                'time' => $row->unlocked_at,
                'text' => "Unlocked **{$row->cosmetic->name}**",
                'kind' => 'cosmetic',
            ]);

        $dungeonClears = DungeonRun::where('character_id', $character->id)
            ->where('status', 'completed')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->with('dungeon')
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (DungeonRun $row) => [
                'time' => $row->updated_at,
                'text' => "Cleared **{$row->dungeon->name}**",
                'kind' => 'dungeon_clear',
            ]);

        $marketSales = MarketListing::where('seller_character_id', $character->id)
            ->where('status', 'sold')
            ->when($since, fn ($q) => $q->where('sold_at', '>=', $since))
            ->with('item')
            ->latest('sold_at')
            ->limit(10)
            ->get()
            ->map(fn (MarketListing $row) => [
                'time' => $row->sold_at,
                'text' => "Sold **{$row->item->name}** for {$row->price_gold}g",
                'kind' => 'market_sale',
            ]);

        $crafts = CraftingJob::where('character_id', $character->id)
            ->whereNotNull('collected_at')
            ->when($since, fn ($q) => $q->where('collected_at', '>=', $since))
            ->with('resultItem')
            ->latest('collected_at')
            ->limit(10)
            ->get()
            ->map(fn (CraftingJob $row) => [
                'time' => $row->collected_at,
                'text' => $row->result_qty > 1
                    ? "Crafted **{$row->resultItem->name}** x{$row->result_qty}"
                    : "Crafted **{$row->resultItem->name}**",
                'kind' => 'craft',
            ]);

        $pets = CharacterPet::where('character_id', $character->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->with('pet')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (CharacterPet $row) => [
                'time' => $row->created_at,
                'text' => "Obtained the pet **{$row->pet->name}**",
                'kind' => 'pet',
            ]);

        $guildJoins = GuildMember::where('character_id', $character->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->with('guild')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (GuildMember $row) => [
                'time' => $row->created_at,
                'text' => "Joined the guild **{$row->guild->name}**",
                'kind' => 'guild_join',
            ]);

        return $achievements->concat($duels)->concat($cosmetics)
            ->concat($dungeonClears)->concat($marketSales)->concat($crafts)->concat($pets)->concat($guildJoins);
    }

    /** Real fields only — no per-member "contribution" counter exists on GuildMember, so this deliberately
     * doesn't show one. */
    private function guildCard(Character $character): ?array
    {
        $membership = $character->guildMembership;
        if (! $membership?->guild) {
            return null;
        }

        return [
            'name' => $membership->guild->name,
            'tag' => $membership->guild->tag,
            'role' => $membership->role,
            'level' => $membership->guild->level,
            'member_count' => $membership->guild->members()->count(),
        ];
    }

    /** The 4 categories this character actually ranks best in, out of all 18 leaderboard boards — not a
     * fixed curated set, so a Level-100 warrior and a gold-hoarding trader each see their own real
     * strengths surfaced here. */
    private function rankings(Character $character): array
    {
        return collect($this->leaderboard->myRanksFor($character))
            ->filter()
            ->sort()
            ->take(4)
            ->map(fn (int $rank, string $category) => [
                'category' => $category,
                'label' => LeaderboardService::CATEGORIES[$category]['label'],
                'rank' => $rank,
            ])
            ->values()
            ->all();
    }

    private function battlePassSummary(Character $character): array
    {
        $pass = $this->battlePass->passFor($character);

        return [
            'season' => ucfirst(BattlePassService::SEASON),
            'tier' => $pass->tier,
            'total_tiers' => BattlePassService::TOTAL_TIERS,
            'premium' => $pass->premium,
        ];
    }
}
