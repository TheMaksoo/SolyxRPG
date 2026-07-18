<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\ClassProgression;
use App\Models\User;
use App\Services\AttributeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
    public function __construct(private AttributeService $attributeService) {}

    /** gems required to unlock gem-slot tiers 1-3 (slot 1 is the free starter slot). */
    private const GEM_SLOT_COSTS = [1 => 750, 2 => 1500, 3 => 4000];

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
            ->with(['attributes_', 'skills.skill'])
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

                $characterData = [
                    'id' => $character->id,
                    'name' => $character->name,
                    'base_class' => $character->base_class,
                    'level' => $character->level,
                    'hp_max' => $character->hp_max,
                    'base_atk' => $character->base_atk,
                    'base_def' => $character->base_def,
                    'mana_max' => $character->mana_max,
                    'luck' => $character->attributes_?->luck ?? 0,
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
        abort_unless($character->user_id === $request->user()->id, 403);

        $user = $request->user();
        $user->active_character_id = $character->id;
        $user->save();

        $character->load(['attributes_', 'zone', 'inventory.item', 'skills.skill']);
        $character->applyPassiveRegen();

        return response()->json([
            'character' => $character,
            'stats' => $character->effectiveStats() + ['xp_max' => Character::xpForLevel($character->level)],
        ]);
    }

    public function destroy(Request $request, Character $character)
    {
        $user = $request->user();
        abort_unless($character->user_id === $user->id, 403);

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
        abort_unless($payer->user_id === $user->id, 403);

        $tier = $user->bonus_character_slots + 1;
        $cost = self::GEM_SLOT_COSTS[$tier];

        if ($payer->gems < $cost) {
            return response()->json(['message' => "Not enough gems. Requires {$cost} gems."], 422);
        }

        $payer->decrement('gems', $cost);
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
            ->with(['attributes_', 'zone', 'inventory.item', 'skills.skill'])
            ->first();

        if (! $character) {
            return response()->json(['message' => 'No character yet.'], 404);
        }

        $character->applyPassiveRegen();

        return response()->json([
            'character' => $character,
            'stats' => $character->effectiveStats() + ['xp_max' => Character::xpForLevel($character->level)],
            'regen_per_tick' => $character->regenPerTick(),
            'mana_regen_per_tick' => $character->manaRegenPerTick(),
            'attribute_costs' => $this->attributeService->allCosts($character->attributes_ ?? new CharacterAttribute()),
            'in_combat' => Battle::where('character_id', $character->id)->where('status', 'active')->exists(),
        ]);
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

        return response()->json(['character' => $character->fresh('attributes_')], 201);
    }

    public function spendAttribute(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate([
            'attr' => ['required', Rule::in(['damage', 'armor', 'hp_cap', 'hp_regen', 'mana_cap', 'mana_regen', 'crit', 'crit_damage', 'luck', 'dodge', 'energy_cap', 'energy_regen', 'trade_speed'])],
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

    /** Unlocks a skill at rank 1, or — if already unlocked — spends a skill point to upgrade its rank (up to the skill's max_level). */
    public function unlockSkill(Request $request, \App\Models\Skill $skill)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->skill_points < 1) {
            return response()->json(['message' => 'No skill points available.'], 422);
        }

        if ($skill->class_scope !== $character->base_class) {
            return response()->json(['message' => 'That skill is not part of your class.'], 422);
        }

        $existing = $character->skills()->where('skill_id', $skill->id)->first();

        if ($existing) {
            if ($existing->level >= $skill->max_level) {
                return response()->json(['message' => 'Already at max rank.'], 422);
            }
            $existing->increment('level');
        } else {
            if ($character->level < $skill->level_req) {
                return response()->json(['message' => "Requires level {$skill->level_req}."], 422);
            }
            $character->skills()->create(['skill_id' => $skill->id, 'unlocked_at' => now(), 'level' => 1]);
        }

        $character->decrement('skill_points');

        return response()->json(['character' => $character->fresh('skills.skill')]);
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
}
