<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\ClassProgression;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
    /** gems required to unlock gem-slot tiers 1-4 (slots 2, 3, 7, 8) */
    private const GEM_SLOT_COSTS = [1 => 750, 2 => 1500, 3 => 4000, 4 => 6000];

    /** fixed identity of every slot 1-8: free, gems (tier 1-4), or vip (tier name) */
    private const SLOT_DEFS = [
        1 => ['type' => 'free'],
        2 => ['type' => 'gems', 'tier' => 1],
        3 => ['type' => 'gems', 'tier' => 2],
        4 => ['type' => 'vip', 'tier' => 'bronze'],
        5 => ['type' => 'vip', 'tier' => 'gold'],
        6 => ['type' => 'vip', 'tier' => 'diamond'],
        7 => ['type' => 'gems', 'tier' => 3],
        8 => ['type' => 'gems', 'tier' => 4],
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $characters = $user->characters()->orderBy('id')->get();
        $vipSlotsUnlocked = $user->vipCharacterSlots();

        $unlocked = fn (array $def) => match ($def['type']) {
            'free' => true,
            'gems' => $def['tier'] <= $user->bonus_character_slots,
            'vip' => User::VIP_TIER_SLOTS[$def['tier']] <= $vipSlotsUnlocked,
        };

        $slots = [];
        $charIndex = 0;
        foreach (self::SLOT_DEFS as $number => $def) {
            $isUnlocked = $unlocked($def);
            $character = ($isUnlocked && $charIndex < $characters->count()) ? $characters[$charIndex++] : null;

            $slots[] = [
                'number' => $number,
                'unlocked' => $isUnlocked,
                'character' => $character,
                'requirement' => match ($def['type']) {
                    'gems' => ['type' => 'gems', 'tier' => $def['tier'], 'cost' => self::GEM_SLOT_COSTS[$def['tier']]],
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

        return response()->json([
            'character' => $character,
            'stats' => $character->effectiveStats(),
        ]);
    }

    public function unlockSlot(Request $request)
    {
        $user = $request->user();

        if ($user->bonus_character_slots >= 4) {
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

        return response()->json([
            'character' => $character,
            'stats' => $character->effectiveStats(),
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

        [$hp, $atk, $mp] = match ($data['base_class']) {
            'warrior' => [1200, 280, 100],
            'mage' => [820, 180, 520],
            'rogue' => [900, 310, 150],
            'ranger' => [940, 300, 150],
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
            'base_atk' => intdiv($atk, 10),
            'base_def' => 10,
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
            'attr' => ['required', Rule::in(['damage', 'armor', 'hp', 'mp', 'crit'])],
        ]);

        if ($character->attribute_points < 1) {
            return response()->json(['message' => 'No attribute points available.'], 422);
        }

        $attributes = $character->attributes_ ?? $character->attributes_()->create([]);
        $attributes->increment($data['attr']);
        $character->decrement('attribute_points');

        return response()->json([
            'character' => $character->fresh('attributes_'),
            'stats' => $character->fresh('attributes_')->effectiveStats(),
        ]);
    }

    public function unlockSkill(Request $request, \App\Models\Skill $skill)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->skill_points < 1) {
            return response()->json(['message' => 'No skill points available.'], 422);
        }
        if ($character->level < $skill->level_req) {
            return response()->json(['message' => "Requires level {$skill->level_req}."], 422);
        }
        if ($character->skills()->where('skill_id', $skill->id)->exists()) {
            return response()->json(['message' => 'Already unlocked.'], 422);
        }

        $character->skills()->create(['skill_id' => $skill->id, 'unlocked_at' => now()]);
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
