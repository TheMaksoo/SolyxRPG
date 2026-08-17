<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorldMessage;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    /**
     * Lightweight endpoint that returns only IDs/timestamps to check if updates exist.
     * Clients poll this instead of fetching full data every time.
     */
    public function check(Request $request)
    {
        $user = $request->user();
        $character = $user->character;

        // Last world chat message ID - client compares to see if new messages exist
        $lastMessageId = WorldMessage::latest('id')->value('id') ?? 0;

        // Badges updated timestamp - any action that changes badges updates this. Falls back to a
        // stable value (not now()) when null — nothing currently writes this column (BadgeUpdateService
        // exists but isn't wired to any quest/party/friend/battle-pass action yet), so a fallback of
        // now() made this look like it changed on literally every single poll, forcing GameLayout's
        // "only refetch nav-badges on a real change" watcher to refetch every 10 seconds regardless.
        $badgesUpdatedAt = $user->badges_updated_at?->timestamp ?? $user->created_at?->timestamp ?? 0;

        return response()->json([
            'last_message_id' => $lastMessageId,
            'badges_updated_at' => $badgesUpdatedAt,
        ]);
    }
}
