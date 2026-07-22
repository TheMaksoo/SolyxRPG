<?php

namespace App\Http\Controllers\Api;

use App\Events\NewChatMessage;
use App\Http\Controllers\Controller;
use App\Models\WorldMessage;
use Illuminate\Http\Request;

class WorldChatController extends Controller
{
    public function index(Request $request)
    {
        $messages = WorldMessage::with(['character.user', 'character.activeColor'])
            ->latest('created_at')
            ->limit(self::RETAIN)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (WorldMessage $m) => $this->withVipTier($m));

        return response()->json(['messages' => $messages]);
    }

    /** How many world messages to retain — old ones are pruned on every send so the table never grows unbounded. */
    private const RETAIN = 100;

    public function send(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['body' => ['required', 'string', 'max:300']]);

        $message = WorldMessage::create([
            'character_id' => $character->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        $this->prune();

        $fullMessage = $this->withVipTier($message->load(['character.user', 'character.activeColor']));

        // Broadcast to all connected clients
        broadcast(new NewChatMessage($fullMessage));

        return response()->json(['message_sent' => $fullMessage]);
    }

    private function prune(): void
    {
        $cutoffId = WorldMessage::orderByDesc('id')->skip(self::RETAIN)->take(1)->value('id');
        if ($cutoffId) {
            WorldMessage::where('id', '<=', $cutoffId)->delete();
        }
    }

    private function withVipTier(WorldMessage $message): WorldMessage
    {
        $message->setAttribute('vip_tier', $message->character?->user?->hasActiveVip() ? $message->character->user->vip_tier : 'none');

        return $message;
    }
}
