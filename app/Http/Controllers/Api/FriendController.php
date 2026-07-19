<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterFavorite;
use App\Models\Friendship;
use App\Services\AchievementService;
use Illuminate\Http\Request;

class FriendController extends Controller
{
    public function __construct(
        private AchievementService $achievements = new AchievementService(),
    ) {
    }

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $friends = $character->friends();
        $favoriteIds = $character->favorites()->pluck('favorite_character_id');

        $incoming = $character->receivedFriendRequests()->where('status', 'pending')->with('requester')->get();

        $friendIds = $friends->pluck('id')->push($character->id);
        $browse = Character::whereNotIn('id', $friendIds)->limit(30)->get(['id', 'name', 'base_class', 'level']);

        return response()->json([
            'friends' => $friends->map(fn ($f) => [
                'character' => $f,
                'favorite' => $favoriteIds->contains($f->id),
            ])->values(),
            'incoming_requests' => $incoming,
            'browse' => $browse,
        ]);
    }

    public function request(Request $request, Character $target)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->id === $target->id, 422, 'Cannot friend yourself.');

        $exists = Friendship::where(function ($q) use ($character, $target) {
            $q->where('requester_id', $character->id)->where('addressee_id', $target->id);
        })->orWhere(function ($q) use ($character, $target) {
            $q->where('requester_id', $target->id)->where('addressee_id', $character->id);
        })->first();

        if ($exists) {
            return response()->json(['message' => 'Request already exists.'], 422);
        }

        $friendship = Friendship::create([
            'requester_id' => $character->id,
            'addressee_id' => $target->id,
            'status' => 'pending',
        ]);

        return response()->json(['friendship' => $friendship], 201);
    }

    public function accept(Request $request, Friendship $friendship)
    {
        $character = $request->user()->character;
        abort_unless($friendship->addressee_id === $character?->id, 403);

        $friendship->update(['status' => 'accepted']);

        $this->achievements->check($character->fresh());
        $requester = Character::find($friendship->requester_id);
        if ($requester) {
            $this->achievements->check($requester);
        }

        return response()->json(['friendship' => $friendship->fresh()]);
    }

    public function decline(Request $request, Friendship $friendship)
    {
        $character = $request->user()->character;
        abort_unless($friendship->addressee_id === $character?->id, 403);

        $friendship->delete();

        return response()->json(['message' => 'Declined.']);
    }

    public function remove(Request $request, Character $target)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        Friendship::where(function ($q) use ($character, $target) {
            $q->where('requester_id', $character->id)->where('addressee_id', $target->id);
        })->orWhere(function ($q) use ($character, $target) {
            $q->where('requester_id', $target->id)->where('addressee_id', $character->id);
        })->delete();

        return response()->json(['message' => 'Removed.']);
    }

    public function toggleFavorite(Request $request, Character $target)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $favorite = CharacterFavorite::where('character_id', $character->id)
            ->where('favorite_character_id', $target->id)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return response()->json(['favorite' => false]);
        }

        CharacterFavorite::create(['character_id' => $character->id, 'favorite_character_id' => $target->id]);

        return response()->json(['favorite' => true]);
    }
}
