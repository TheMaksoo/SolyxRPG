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

        // Badges updated timestamp - any action that changes badges updates this
        $badgesUpdatedAt = $user->badges_updated_at?->timestamp ?? now()->timestamp;

        return response()->json([
            'last_message_id' => $lastMessageId,
            'badges_updated_at' => $badgesUpdatedAt,
        ]);
    }
}
