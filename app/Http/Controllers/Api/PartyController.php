<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\Friendship;
use App\Models\Party;
use App\Models\PartyInvite;
use App\Models\PartyMember;
use Illuminate\Http\Request;

class PartyController extends Controller
{
    /** Kept small on purpose — this is a lightweight friend group, not a Guild. */
    public const MAX_SIZE = 4;

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('party', $request->user()), 403, 'Party is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $party = $character->partyMembership?->party?->load([
            'members.character.activeTitle',
            'members.character.activeColor',
            'leader',
            'messages' => fn ($q) => $q->limit(50)->with('character.activeColor'),
        ]);
        $invites = PartyInvite::where('character_id', $character->id)->with(['party.leader', 'inviter'])->get();

        return response()->json([
            'party' => $party,
            'party_bonuses' => $character->partyBonuses(),
            'invites' => $invites,
        ]);
    }

    public function store(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->partyMembership, 422, 'Already in a party — leave it first.');

        $party = Party::create(['leader_character_id' => $character->id]);
        $party->members()->create(['character_id' => $character->id]);

        return response()->json(['party' => $party->load('members.character')], 201);
    }

    public function invite(Request $request, Character $target)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->id === $target->id, 422, 'Cannot invite yourself.');

        $party = $character->partyMembership?->party;
        abort_unless($party, 422, 'You are not in a party.');
        abort_unless($party->leader_character_id === $character->id, 403, 'Only the party leader can invite.');
        abort_if($party->members()->count() >= self::MAX_SIZE, 422, 'Party is full.');
        abort_if($target->partyMembership, 422, "{$target->name} is already in a party.");

        $areFriends = Friendship::where(function ($q) use ($character, $target) {
            $q->where('requester_id', $character->id)->where('addressee_id', $target->id);
        })->orWhere(function ($q) use ($character, $target) {
            $q->where('requester_id', $target->id)->where('addressee_id', $character->id);
        })->where('status', 'accepted')->exists();
        abort_unless($areFriends, 422, 'You can only invite friends to your party.');

        $invite = PartyInvite::firstOrCreate(
            ['party_id' => $party->id, 'character_id' => $target->id],
            ['inviter_character_id' => $character->id],
        );

        return response()->json(['invite' => $invite], 201);
    }

    public function acceptInvite(Request $request, PartyInvite $invite)
    {
        $character = $request->user()->character;
        abort_unless($character && $invite->character_id === $character->id, 403, 'This invite is not addressed to your current character.');
        abort_if($character->partyMembership, 422, 'Already in a party — leave it first.');

        $party = $invite->party;
        abort_if($party->members()->count() >= self::MAX_SIZE, 422, 'Party is full.');

        $party->members()->create(['character_id' => $character->id]);
        PartyInvite::where('character_id', $character->id)->delete();

        return response()->json(['party' => $party->load('members.character')]);
    }

    public function declineInvite(Request $request, PartyInvite $invite)
    {
        $character = $request->user()->character;
        abort_unless($character && $invite->character_id === $character->id, 403, 'This invite is not addressed to your current character.');

        $invite->delete();

        return response()->json(['message' => 'Declined.']);
    }

    public function leave(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $membership = $character->partyMembership;
        abort_unless($membership, 422, 'Not in a party.');

        $party = $membership->party;
        if ($party->leader_character_id === $character->id) {
            PartyInvite::where('party_id', $party->id)->delete();
            $party->members()->delete();
            $party->delete();
        } else {
            $membership->delete();
        }

        return response()->json(['message' => 'Left the party.']);
    }

    public function message(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $party = $character->partyMembership?->party;
        abort_unless($party, 422, 'You are not in a party.');

        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $message = $party->messages()->create([
            'character_id' => $character->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        return response()->json(['message_sent' => $message->load('character.activeColor')]);
    }

    public function kick(Request $request, Character $target)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $party = $character->partyMembership?->party;
        abort_unless($party, 422, 'You are not in a party.');
        abort_unless($party->leader_character_id === $character->id, 403, 'Only the party leader can kick.');
        abort_if($target->id === $character->id, 422, 'Use "Leave" to disband the party instead.');

        $party->members()->where('character_id', $target->id)->delete();

        return response()->json(['party' => $party->fresh('members.character')]);
    }
}
