<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Purchase;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $character = $user->character;

        $items = collect();

        foreach (Announcement::latest('created_at')->limit(10)->get() as $a) {
            $items->push([
                'type' => 'announcement',
                'icon' => '📣',
                'title' => 'Announcement',
                'body' => $a->body,
                'time' => $a->created_at,
                'invite' => false,
            ]);
        }

        foreach (Purchase::where('user_id', $user->id)->where('status', 'completed')->latest()->limit(10)->get() as $p) {
            $items->push([
                'type' => 'purchase',
                'icon' => '🧾',
                'title' => 'Purchase receipt',
                'body' => "{$p->sku} — $".number_format($p->amount_cents / 100, 2),
                'time' => $p->updated_at,
                'invite' => false,
            ]);
        }

        if ($character) {
            foreach ($character->receivedFriendRequests()->where('status', 'pending')->with('requester')->get() as $f) {
                $items->push([
                    'type' => 'friend_request',
                    'icon' => '🧑‍🤝‍🧑',
                    'title' => 'Friend request',
                    'body' => "{$f->requester->name} wants to be friends.",
                    'time' => $f->created_at,
                    'invite' => true,
                    'friendship_id' => $f->id,
                ]);
            }
        }

        return response()->json([
            'items' => $items->sortByDesc('time')->values(),
        ]);
    }
}
