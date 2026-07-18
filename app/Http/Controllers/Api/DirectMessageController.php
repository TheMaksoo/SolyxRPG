<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\DirectMessage;
use Illuminate\Http\Request;

class DirectMessageController extends Controller
{
    public function thread(Request $request, Character $target)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $messages = DirectMessage::where(function ($q) use ($character, $target) {
            $q->where('sender_id', $character->id)->where('recipient_id', $target->id);
        })->orWhere(function ($q) use ($character, $target) {
            $q->where('sender_id', $target->id)->where('recipient_id', $character->id);
        })->orderBy('created_at')->get();

        DirectMessage::where('sender_id', $target->id)
            ->where('recipient_id', $character->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['messages' => $messages]);
    }

    public function send(Request $request, Character $target)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);

        $message = DirectMessage::create([
            'sender_id' => $character->id,
            'recipient_id' => $target->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        return response()->json(['message_sent' => $message], 201);
    }
}
