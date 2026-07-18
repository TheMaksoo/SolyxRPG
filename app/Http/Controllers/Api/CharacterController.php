<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterAttribute;
use App\Models\ClassProgression;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
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
        if ($request->user()->character) {
            return response()->json(['message' => 'Character already exists.'], 422);
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
            'user_id' => $request->user()->id,
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
