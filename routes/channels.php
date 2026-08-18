<?php

use App\Models\PvpLiveMatch;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('world-chat', function () {
    return true; // Public channel
});

Broadcast::channel('user.{userId}.badges', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('user.{userId}.pvp', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Reuses PvpLiveMatch::sideFor() — the same method PvpController::characterInMatch()/matchPayload()
// already use — so channel auth enforces the identical "are you actually in this match" rule the
// REST endpoints already enforce.
Broadcast::channel('pvp-match.{matchId}', function ($user, $matchId) {
    $character = $user->character;
    if (! $character) {
        return false;
    }

    $match = PvpLiveMatch::find($matchId);

    return $match && $match->sideFor($character->id) !== null;
});
