<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorldMessage;
use Illuminate\Http\Request;

class WorldChatController extends Controller
{
    public function index(Request $request)
    {
        $messages = WorldMessage::with('character')->latest('created_at')->limit(50)->get()->reverse()->values();

        return response()->json(['messages' => $messages]);
    }

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

        return response()->json(['message_sent' => $message->load('character')]);
    }
}
