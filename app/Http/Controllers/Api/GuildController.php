<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guild;
use Illuminate\Http\Request;

class GuildController extends Controller
{
    /** Returns the character's own guild (roster + recent chat), or a browse list if not in one. */
    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $membership = $character->guildMembership;

        if (! $membership) {
            return response()->json(['guild' => null, 'browse' => Guild::withCount('members')->get()]);
        }

        $guild = $membership->guild()->with(['members.character', 'messages' => fn ($q) => $q->latest('created_at')->limit(50)])->first();

        return response()->json(['guild' => $guild, 'my_role' => $membership->role]);
    }

    public function store(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->guildMembership, 422, 'Already in a guild.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'tag' => ['required', 'string', 'max:5'],
        ]);

        $guild = Guild::create([...$data, 'owner_id' => $character->id]);
        $guild->members()->create(['character_id' => $character->id, 'role' => 'master']);

        return response()->json(['guild' => $guild->fresh('members')], 201);
    }

    public function join(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->guildMembership, 422, 'Already in a guild.');

        if ($guild->members()->count() >= $guild->member_cap) {
            return response()->json(['message' => 'Guild is full.'], 422);
        }

        $guild->members()->create(['character_id' => $character->id, 'role' => 'member']);

        return response()->json(['guild' => $guild->fresh('members.character')]);
    }

    public function message(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_unless($guild->members()->where('character_id', $character->id)->exists(), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $message = $guild->messages()->create([
            'character_id' => $character->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        return response()->json(['message_sent' => $message->load('character')]);
    }
}
