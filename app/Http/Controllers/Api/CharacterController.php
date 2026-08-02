<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Battle;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\ClassProgression;
use App\Models\Cosmetic;
use App\Models\DungeonRun;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\LegacyDiscordUser;
use App\Models\PvpLiveMatch;
use App\Models\PvpMatch;
use App\Models\User;
use App\Services\AttributeService;
use App\Services\LeaderboardService;
use App\Services\QuestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
    public function __construct(
        private AttributeService $attributeService,
        private LeaderboardService $leaderboard,
        private QuestService $quests = new QuestService(),
    ) {}

    /** gems required to unlock gem-slot tiers 1-3 (slot 1 is the free starter slot). */
    public const GEM_SLOT_COSTS = [1 => 750, 2 => 1500, 3 => 4000];

    /** fixed identity of every slot 1-8: gems-first, then subscription slots. */
    private const SLOT_DEFS = [
        1 => ['type' => 'gems', 'tier' => 0],
        2 => ['type' => 'gems', 'tier' => 1],
        3 => ['type' => 'gems', 'tier' => 2],
        4 => ['type' => 'gems', 'tier' => 3],
        5 => ['type' => 'vip', 'tier' => 'bronze'],
        6 => ['type' => 'vip', 'tier' => 'gold'],
        7 => ['type' => 'vip', 'tier' => 'diamond'],
        8 => ['type' => 'vip', 'tier' => 'diamond'],
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $characters = $user->characters()
            ->with(['attributes_', 'skills.skill', 'inventory.item', 'pets.pet', 'user'])
            ->orderBy('id')
            ->get();
        $vipSlotsUnlocked = $user->vipCharacterSlots();

        $unlocked = fn (array $def) => match ($def['type']) {
            'free' => true,
            'gems' => $def['tier'] === 0 || $def['tier'] <= $user->bonus_character_slots,
            'vip' => User::VIP_TIER_SLOTS[$def['tier']] <= $vipSlotsUnlocked,
        };

        $slots = [];
        $charIndex = 0;
        foreach (self::SLOT_DEFS as $number => $def) {
            $isUnlocked = $unlocked($def);
            $character = ($isUnlocked && $charIndex < $characters->count()) ? $characters[$charIndex++] : null;

            $characterData = null;
            if ($character) {
                $firstSkill = $character->skills
                    ->sortBy(fn ($row) => [$row->skill->tier ?? 999, $row->unlocked_at])
                    ->firstWhere('skill', '!=', null)
                    ?->skill;

                $stats = $character->effectiveStats();

                $characterData = [
                    'id' => $character->id,
                    'name' => $character->name,
                    'base_class' => $character->base_class,
                    'level' => $character->level,
                    'eff_hp_max' => $stats['eff_hp_max'],
                    'eff_atk' => $stats['eff_atk'],
                    'eff_def' => $stats['eff_def'],
                    'eff_mp_max' => $stats['eff_mp_max'],
                    'luck' => $stats['luck'] ?? 0,
                    'first_skill' => $firstSkill ? [
                        'name' => $firstSkill->name,
                        'glyph' => $firstSkill->glyph,
                    ] : null,
                ];
            }

            $slots[] = [
                'number' => $number,
                'unlocked' => $isUnlocked,
                'character' => $characterData,
                'requirement' => match ($def['type']) {
                    'gems' => $def['tier'] === 0
                        ? ['type' => 'free']
                        : ['type' => 'gems', 'tier' => $def['tier'], 'cost' => self::GEM_SLOT_COSTS[$def['tier']]],
                    'vip' => ['type' => 'vip', 'tier' => $def['tier']],
                    default => ['type' => 'free'],
                },
            ];
        }

        return response()->json([
            'slots' => $slots,
            'active_character_id' => $user->active_character_id,
            'max_slots' => $user->maxCharacterSlots(),
            'bonus_character_slots' => $user->bonus_character_slots,
            'vip_tier' => $user->vip_tier,
            'vip_active' => $user->hasActiveVip(),
        ]);
    }

    public function select(Request $request, Character $character)
    {
        $this->logIfOwnershipMismatch($request, $character, 'select');
        abort_unless($character->user_id === $request->user()->id, 403, 'That character does not belong to your account.');

        $user = $request->user();
        $user->active_character_id = $character->id;
        $user->save();

        $character->load(['attributes_', 'zone', 'inventory.item', 'skills.skill', 'user']);
        $character->applyPassiveRegen();

        return response()->json([
            'character' => $character,
            'stats' => $character->effectiveStats() + [
                'xp_max' => Character::xpForLevel($character->level),
                'xp_min' => Character::xpForLevel(max(1, $character->level - 1)),
            ],
        ]);
    }

    public function destroy(Request $request, Character $character)
    {
        $user = $request->user();
        $this->logIfOwnershipMismatch($request, $character, 'destroy');
        abort_unless($character->user_id === $user->id, 403, 'That character does not belong to your account.');

        $deletedId = $character->id;
        $character->delete();

        if ((int) $user->active_character_id === $deletedId) {
            $next = $user->characters()->orderBy('id')->first();
            $user->active_character_id = $next?->id;
            $user->save();
        }

        return response()->json([
            'deleted_character_id' => $deletedId,
            'active_character_id' => $user->fresh()->active_character_id,
        ]);
    }

    public function unlockSlot(Request $request)
    {
        $user = $request->user();

        if ($user->bonus_character_slots >= 3) {
            return response()->json(['message' => 'All gem-purchasable slots are already unlocked.'], 422);
        }

        $data = $request->validate(['character_id' => ['required', 'exists:characters,id']]);
        $payer = Character::findOrFail($data['character_id']);
        $this->logIfOwnershipMismatch($request, $payer, 'unlockSlot');
        abort_unless($payer->user_id === $user->id, 403, 'That character does not belong to your account.');

        $tier = $user->bonus_character_slots + 1;
        $cost = self::GEM_SLOT_COSTS[$tier];

        if ($user->gems < $cost) {
            return response()->json(['message' => "Not enough gems. Requires {$cost} gems."], 422);
        }

        $user->decrement('gems', $cost);
        GemLedger::log($user, -$cost, "character_slot_unlock:tier{$tier}", $payer);
        $user->increment('bonus_character_slots');

        return response()->json([
            'bonus_character_slots' => $user->bonus_character_slots,
            'max_slots' => $user->fresh()->maxCharacterSlots(),
            'paid_character' => $payer->fresh(),
        ]);
    }

    public function show(Request $request)
    {
        $character = $request->user()->character()
            ->with(['attributes_', 'zone', 'inventory.item', 'skills.skill', 'activeTitle', 'activeColor', 'activeBanner', 'activeIcon', 'activeFrame', 'user'])
            ->first();

        if (! $character) {
            return response()->json(['message' => 'No character yet.'], 404);
        }

        $character->applyPassiveRegen();
        $vipGemsGranted = $request->user()->grantMonthlyVipGemsIfDue($character);

        return response()->json([
            'character' => $vipGemsGranted > 0 ? $character->fresh(['attributes_', 'zone', 'inventory.item', 'skills.skill', 'activeTitle', 'activeColor', 'activeBanner', 'activeIcon', 'activeFrame', 'user']) : $character,
            'stats' => $character->effectiveStats() + [
                'xp_max' => Character::xpForLevel($character->level),
                'xp_min' => $character->level > 1 ? Character::xpForLevel($character->level - 1) : 0,
            ],
            'regen_per_tick' => $character->regenPerTick(),
            'mana_regen_per_tick' => $character->manaRegenPerTick(),
            'energy_regen_per_tick' => $character->energyRegenPerTick(),
            'attribute_costs' => $this->attributeService->allCosts($character->attributes_ ?? new CharacterAttribute()),
            'in_combat' => Battle::where('character_id', $character->id)->where('status', 'active')->where('is_auto', false)->exists(),
            'vip_gems_granted' => $vipGemsGranted,
        ]);
    }

    /** The one periodic "game save" the client pushes (every ~5 real seconds, see composables/regen.js) —
     * everything else touching a character (show(), battle fetches, etc.) only ever reads/recomputes its
     * own state, never a client-reported one. The client already knows its own HP/MP/energy exactly (it's
     * computing the same real-elapsed-time × rate formula the server does, continuously rather than in
     * discrete ticks), so rather than have the server *re-derive* a number and risk it disagreeing with
     * what's on screen, the client pushes what it's showing and the server just clamps it to what could
     * legitimately have regenerated (Character::maxPlausibleValue) and persists it. This is what makes a
     * reload reflect exactly what was on screen a moment before, instead of the server's own independent
     * (differently-rounded) recompute silently overwriting it. */
    public function saveTick(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'hp' => ['sometimes', 'integer', 'min:0'],
            'mana' => ['sometimes', 'integer', 'min:0'],
            'energy' => ['sometimes', 'integer', 'min:0'],
            'battle_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $stats = $character->effectiveStats();

        $battle = ! empty($data['battle_id'])
            ? Battle::where('id', $data['battle_id'])
                ->where('character_id', $character->id)
                ->where('status', 'active')
                ->where('is_auto', false)
                ->first()
            : null;

        if ($battle && array_key_exists('hp', $data)) {
            // In an active manual battle, the client's hp is the battle's own live HP counter (Battle::
            // character_hp) — Character::hp stays frozen for the whole fight (see resolveWin/resolveLoss).
            $ceiling = $character->maxPlausibleValue($battle->character_hp, $battle->updated_at, $stats['eff_hp_max'], $character->regenPerTick());
            $newHp = min($data['hp'], $ceiling);
            if ($newHp !== $battle->character_hp) {
                $battle->character_hp = $newHp;
                $battle->save();
            }
        } elseif (array_key_exists('hp', $data)) {
            // Deliberately not applyPassiveRegen() here — that would advance last_regen_at itself and
            // collapse the ceiling to ~0 elapsed time before maxPlausibleValue ever sees the real gap,
            // right back to the server silently overriding the client's more precise number.
            $ceiling = $character->maxPlausibleValue($character->hp, $character->last_regen_at, $stats['eff_hp_max'], $character->regenPerTick());
            $character->hp = min($data['hp'], $ceiling);
        }

        if (array_key_exists('mana', $data)) {
            if ($battle) {
                // Mana regen is paused for the whole of an active manual battle, same as passive HP regen
                // (see Character::applyPassiveRegen) — only real drains (skill costs, items) can move it
                // here, so the ceiling can't grow above whatever's already saved.
                $character->mana = min($data['mana'], $character->mana);
            } else {
                $ceiling = $character->maxPlausibleValue($character->mana, $character->last_mana_regen_at, $stats['eff_mp_max'], $character->manaRegenPerTick());
                $character->mana = min($data['mana'], $ceiling);
            }
        }
        if (array_key_exists('energy', $data)) {
            $ceiling = $character->maxPlausibleValue($character->energy, $character->last_energy_regen_at, $stats['eff_energy_max'], $character->energyRegenPerTick());
            $character->energy = min($data['energy'], $ceiling);
        }

        $character->last_regen_at = now();
        $character->last_mana_regen_at = now();
        $character->last_energy_regen_at = now();
        $character->save();

        if ($character->registerSyncCall()) {
            Log::warning('Character exceeded daily save-tick budget — possible tick-speed manipulation', [
                'character_id' => $character->id,
                'user_id' => $request->user()->id,
                'sync_calls_today' => $character->sync_calls_today,
            ]);
        }

        return response()->json([
            'hp' => $character->hp,
            'mana' => $character->mana,
            'energy' => $character->energy,
            'battle_hp' => $battle?->character_hp,
            'regen_per_tick' => $character->regenPerTick(),
            'mana_regen_per_tick' => $character->manaRegenPerTick(),
            'energy_regen_per_tick' => $character->energyRegenPerTick(),
        ]);
    }

    /** Real per-character activity data for the Profile page's Stats tab — a 14-day battle trend, a
     * lifetime content-mix breakdown, most-used skills, and a couple of extra fun counts (pets/cosmetics
     * collected). Every number is a live query or an existing flat counter column, nothing simulated. */
    public function activity(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $since = now()->subDays(13)->startOfDay();
        $rows = Battle::where('character_id', $character->id)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, count(*) as count')
            ->groupBy('day')
            ->pluck('count', 'day');

        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $trend[] = ['date' => $day, 'count' => (int) ($rows[$day] ?? 0)];
        }

        // Top 5 skills by times_used (see CombatService::act()'s skill branch) — deliberately excludes
        // battles_won/battles_lost from the breakdown below since those already show in the profile hero
        // line and the win/loss chart; this endpoint shouldn't repeat numbers shown elsewhere on the page.
        $topSkills = $character->skills()
            ->with('skill')
            ->where('times_used', '>', 0)
            ->orderByDesc('times_used')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['key' => $row->skill->key, 'label' => $row->skill->name, 'count' => $row->times_used]);

        // A broad lifetime activity mix across content systems (mirrors GmAnalyticsController's
        // "what players are doing" breakdown, just scoped to this one character) — deliberately distinct
        // from the granular per-resource gathering chips rendered separately below it on the page, so the
        // two panels never repeat the same numbers.
        $gatheringTotal = ($character->times_mined ?? 0) + ($character->times_chopped ?? 0)
            + ($character->times_smelted ?? 0) + ($character->times_foraged ?? 0);

        // Battles deliberately excluded from this breakdown — its count so dwarfs everything else that
        // the other bars become invisible next to it, and battles already get their own trend line and
        // win/loss chart above.
        return response()->json([
            'battle_trend_14d' => $trend,
            'activity_breakdown' => [
                ['key' => 'dungeons', 'label' => 'Dungeon Runs', 'count' => DungeonRun::where('character_id', $character->id)->count()],
                ['key' => 'pvp', 'label' => 'PvP Matches', 'count' => PvpLiveMatch::where('character_a_id', $character->id)->orWhere('character_b_id', $character->id)->count()],
                ['key' => 'crafted', 'label' => 'Items Crafted', 'count' => $character->times_crafted ?? 0],
                ['key' => 'quests', 'label' => 'Quests Completed', 'count' => $character->quests_completed ?? 0],
                ['key' => 'gathering', 'label' => 'Gathering Actions', 'count' => $gatheringTotal],
            ],
            'top_skills' => $topSkills,
            'pets_collected' => $character->pets()->count(),
            'cosmetics_unlocked' => $character->cosmetics()->count(),
        ]);
    }

    /** Marks the new-character guided tour as seen so it never shows again for this character. Also grants
     * the "Initiate" title the first time it's completed — a permanent record of finishing onboarding. */
    public function dismissTutorial(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $character->update(['tutorial_seen' => true]);

        $title = Cosmetic::where('unlock_event', 'tutorial_complete')->where('enabled', true)->first();
        if ($title && ! $character->cosmetics()->where('cosmetic_id', $title->id)->exists()) {
            $character->cosmetics()->create(['cosmetic_id' => $title->id]);
        }

        return response()->json(['character' => $character->fresh()]);
    }

    /** Lets a player replay the guided tour on demand (e.g. from Settings) instead of only ever seeing it once. */
    public function restartTutorial(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $character->update(['tutorial_seen' => false]);

        return response()->json(['character' => $character->fresh()]);
    }

    /** Public-safe view of another character's profile — no gold/gems/inventory/email, just what's shown off. */
    public function publicProfile(Request $request, Character $character)
    {
        $character->load(['user', 'zone', 'activeTitle', 'activeColor', 'activeBanner', 'activeIcon', 'activeFrame', 'guildMembership.guild', 'pvpRecord']);

        $earned = $character->achievements()->count();
        $totalAchievements = Achievement::where('enabled', true)->count();

        // Per-field visibility (Character::privacySetting()) — applied uniformly regardless of who's
        // viewing, including the character's own owner, so "Share link" honestly previews exactly what
        // everyone else sees rather than quietly exempting yourself.
        $gearVisible = $character->privacySetting('gear_visible');
        $combatVisible = $character->privacySetting('combat_stats_visible');
        $playtimeVisible = $character->privacySetting('playtime_visible');
        $gear = $gearVisible ? $this->publicEquippedGear($character) : [];

        return response()->json([
            'character' => [
                'id' => $character->id,
                'name' => $character->name,
                'level' => $character->level,
                'base_class' => $character->base_class,
                'spec_class' => $character->spec_class,
                'profession' => $character->profession,
                'battles_won' => $character->battles_won,
                'battles_lost' => $character->battles_lost,
                'bosses_slain' => $character->bosses_slain,
                'quests_completed' => $character->quests_completed,
                'times_mined' => $character->times_mined,
                'times_chopped' => $character->times_chopped,
                'times_smelted' => $character->times_smelted,
                'times_foraged' => $character->times_foraged,
                'times_crafted' => $character->times_crafted,
                'active_title' => $character->activeTitle,
                'active_color' => $character->activeColor,
                'active_banner' => $character->activeBanner,
                'active_icon' => $character->activeIcon,
                'active_frame' => $character->activeFrame,
                'bio' => $character->bio,
                'playstyle_tags' => $character->playstyle_tags ?? [],
                'is_online' => $character->updated_at?->gt(now()->subMinutes(3)) ?? false,
                'zone_name' => $character->zone?->name,
                'vip_tier' => $character->user?->hasActiveVip() ? $character->user->vip_tier : 'none',
                'guild' => $character->guildMembership?->guild ? [
                    'name' => $character->guildMembership->guild->name,
                    'tag' => $character->guildMembership->guild->tag,
                ] : null,
                'pvp_rank' => $character->pvpRecord?->percentileBracket(),
                'pvp_rating' => $character->pvpRecord?->rating,
                'created_at' => $character->created_at,
                'playtime_seconds' => $playtimeVisible ? $character->playtime_seconds : null,
            ],
            'power' => $combatVisible ? $character->effectiveStats()['power'] : null,
            'achievements_earned' => $earned,
            'achievements_total' => $totalAchievements,
            'equipped_gear' => $gear,
            'gear_score' => $gearVisible ? array_sum(array_column($gear, 'score')) : null,
            'guild_card' => $this->publicGuildCard($character),
            'rankings' => $this->publicRankings($character),
            'head_to_head' => $this->headToHead($request->user()->character, $character),
            'privacy' => $character->privacySettingsWithDefaults(),
        ]);
    }

    private function publicEquippedGear(Character $character): array
    {
        return $character->inventory()->where('equipped', true)->with('item')->get()
            ->map(fn ($slot) => [
                'name' => $slot->item->name,
                'glyph' => $slot->item->glyph,
                'rarity' => $slot->item->rarity,
                'slot' => ucfirst($slot->item->type),
                'score' => (int) array_sum(array_filter($slot->item->stat_json ?? [], 'is_numeric')),
            ])
            ->values()
            ->all();
    }

    /** Real fields only — no per-member "contribution" counter exists on GuildMember. */
    private function publicGuildCard(Character $character): ?array
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

    /** The 4 categories this character actually ranks best in — same real, non-curated approach as
     * ProfileController::rankings(), so a viewer sees the same "real strengths" a player sees on their own
     * profile. */
    private function publicRankings(Character $character): array
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

    /** Real match history between the viewer and the profile they're looking at, from PvpMatch (every
     * duel writes one row per side — see PvpLiveCombatService::finish() — so the viewer's own rows already
     * carry their own win/loss perspective, no need to also read the mirrored opponent rows). Null if the
     * viewer has no character (shouldn't happen behind auth:sanctum, but defensive) or the two have never
     * fought. */
    private function headToHead(?Character $viewer, Character $profile): ?array
    {
        if (! $viewer || $viewer->id === $profile->id) {
            return null;
        }

        $matches = PvpMatch::where('character_id', $viewer->id)
            ->where('opponent_id', $profile->id)
            ->orderByDesc('created_at')
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        $viewerWins = $matches->where('result', 'win')->count();
        $profileWins = $matches->where('result', 'loss')->count();
        $last = $matches->first();

        return [
            'your_wins' => $viewerWins,
            'their_wins' => $profileWins,
            'last_result' => $last->result,
            'last_played_at' => $last->created_at,
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->characters()->count() >= $user->maxCharacterSlots()) {
            return response()->json(['message' => 'No open character slots.'], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:30'],
            'base_class' => ['required', Rule::in(['warrior', 'mage', 'rogue', 'ranger'])],
            'avatar' => ['nullable', 'string', 'max:255'],
        ]);

        // Tuned against current starter monsters (30-90 HP, 6-16 ATK) and early item gains
        // so each class has a distinct profile without trivializing early zones.
        [$hp, $baseAtk, $mp, $baseDef] = match ($data['base_class']) {
            'warrior' => [230, 12, 90, 14],
            'mage' => [155, 11, 240, 8],
            'rogue' => [180, 13, 120, 10],
            'ranger' => [195, 12, 140, 11],
        };

        $character = Character::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'base_class' => $data['base_class'],
            'avatar' => $data['avatar'] ?? '',
            'hp' => $hp,
            'hp_max' => $hp,
            'mana' => $mp,
            'mana_max' => $mp,
            'base_atk' => $baseAtk,
            'base_def' => $baseDef,
        ]);

        $character->attributes_()->create([]);

        $user->active_character_id = $character->id;
        $user->save();

        // Covers the order where Discord was linked before this character existed (e.g. signing up
        // via Discord OAuth creates the account first, the character second) — see LegacyDiscordUser.
        LegacyDiscordUser::grantLegendTitleIfMatched($user);

        return response()->json(['character' => $character->fresh('attributes_')], 201);
    }

    public function spendAttribute(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'attr' => ['required', Rule::in(['damage', 'armor', 'hp_cap', 'hp_regen', 'mana_cap', 'mana_regen', 'crit', 'crit_damage', 'luck', 'dodge', 'energy_cap', 'energy_regen', 'mining_speed', 'chopping_speed', 'smelting_speed', 'crafting_speed', 'foraging_speed'])],
        ]);

        $attributes = $character->attributes_ ?? $character->attributes_()->create([]);
        $cost = $this->attributeService->costForNextPoint($data['attr'], $attributes->{$data['attr']});

        if ($character->attribute_points < $cost) {
            return response()->json(['message' => "Not enough attribute points (needs {$cost})."], 422);
        }

        $attributes->increment($data['attr']);
        $character->decrement('attribute_points', $cost);

        return response()->json([
            'character' => $character->fresh('attributes_'),
            'stats' => $character->fresh('attributes_')->effectiveStats() + ['xp_max' => Character::xpForLevel($character->level)],
            'attribute_costs' => $this->attributeService->allCosts($character->fresh('attributes_')->attributes_),
        ]);
    }

    /** Unlocks a skill at rank 1 (cost 1), or — if already unlocked — spends skill points to upgrade its
     * rank (up to the skill's max_level). Each rank costs 1 more point than the last (rank N costs N points
     * — 1/2/3/4/5/6/7/8 for an 8-rank skill, totaling 36) so maxing a skill is a real sink across a
     * character's leveling but doesn't require hoarding points for dozens of levels the way the old
     * quadratic curve (N² — 204 points to max an 8-rank skill, more than a level 150 character ever earns)
     * did. */
    public function unlockSkill(Request $request, \App\Models\Skill $skill)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($skill->class_scope !== $character->base_class) {
            return response()->json(['message' => 'That skill is not part of your class.'], 422);
        }

        $existing = $character->skills()->where('skill_id', $skill->id)->first();

        if ($existing) {
            if ($existing->level >= $skill->max_level) {
                return response()->json(['message' => 'Already at max rank.'], 422);
            }
            $nextRank = $existing->level + 1;
            $nextRankLevel = $skill->levelForRank($nextRank);
            if ($character->level < $nextRankLevel) {
                return response()->json(['message' => "Rank {$nextRank} requires level {$nextRankLevel}."], 422);
            }
            $cost = $nextRank;
            if ($character->skill_points < $cost) {
                return response()->json(['message' => "Rank {$nextRank} costs {$cost} skill points."], 422);
            }
            $existing->increment('level');
            $character->decrement('skill_points', $cost);
        } else {
            if ($character->level < $skill->level_req) {
                return response()->json(['message' => "Requires level {$skill->level_req}."], 422);
            }
            if ($skill->requires_profession && ! $character->spec_class) {
                return response()->json(['message' => 'Choose a profession (Lv.20, see Class Path) first.'], 422);
            }
            // Same branch, one tier below — matches SkillsPage.vue's `prevUnlocked` gating on the frontend.
            // That frontend check was purely cosmetic until now: nothing server-side stopped a client from
            // unlocking a tier-2/3 skill directly (skipping tier-1) as long as level_req + skill_points
            // were met, since only those two conditions were enforced here.
            $prevSkill = \App\Models\Skill::where('branch', $skill->branch)
                ->where('class_scope', $skill->class_scope)
                ->where('tier', '<', $skill->tier)
                ->orderByDesc('tier')
                ->first();
            if ($prevSkill && ! $character->skills()->where('skill_id', $prevSkill->id)->exists()) {
                return response()->json(['message' => "Unlock {$prevSkill->name} first."], 422);
            }
            if ($character->skill_points < 1) {
                return response()->json(['message' => 'No skill points available.'], 422);
            }
            $character->skills()->create(['skill_id' => $skill->id, 'unlocked_at' => now(), 'level' => 1]);
            $this->quests->progressSkillUnlock($character, $skill->key);
            $character->decrement('skill_points');
        }

        $character->refresh();
        $character->load(['attributes_', 'skills.skill', 'zone', 'inventory.item']);
        return response()->json(['character' => $character]);
    }

    public function chooseProfession(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'tier' => ['required', Rule::in(['t20', 't40', 't60'])],
            'key' => ['required', 'string'],
        ]);

        $progression = ClassProgression::where('base_class', $character->base_class)
            ->where('tier', $data['tier'])
            ->where('key', $data['key'])
            ->firstOrFail();

        if ($character->level < $progression->level_cap) {
            return response()->json(['message' => "Requires level {$progression->level_cap}."], 422);
        }

        $column = ['t20' => 'spec_class', 't40' => 'profession', 't60' => 'ascension'][$data['tier']];

        if ($data['tier'] !== 't20' && ! $character->spec_class) {
            return response()->json(['message' => 'Choose your Lv.20 specialization first.'], 422);
        }
        if ($data['tier'] === 't60' && ! $character->profession) {
            return response()->json(['message' => 'Choose your Lv.40 profession first.'], 422);
        }

        $character->update([$column => $progression->key]);

        return response()->json(['character' => $character->fresh()]);
    }

    /** Logs hard evidence (session id, resolved auth user, character's real owner) whenever a character
     * ownership check is about to fail — so a recurrence points at the exact session/user mismatch
     * instead of requiring another round of guessing. No-op when ownership actually matches. */
    private function logIfOwnershipMismatch(Request $request, Character $character, string $action): void
    {
        $user = $request->user();
        if ($character->user_id === $user->id) {
            return;
        }

        Log::warning("Character ownership mismatch on {$action}", [
            'session_id' => $request->session()->getId(),
            'auth_user_id' => $user->id,
            'auth_user_email' => $user->email,
            'character_id' => $character->id,
            'character_owner_id' => $character->user_id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
