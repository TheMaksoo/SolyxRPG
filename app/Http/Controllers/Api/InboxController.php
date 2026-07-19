<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\GemLedger;
use App\Models\Mail;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InboxController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $character = $user->character;

        $items = collect();

        foreach (Mail::where('recipient_user_id', $user->id)->whereNull('dismissed_at')->latest('created_at')->limit(30)->get() as $m) {
            $items->push([
                'id' => $m->id,
                'type' => 'mail',
                'icon' => '✉',
                'title' => $m->subject,
                'body' => $m->body,
                'time' => $m->created_at,
                'invite' => false,
                'read' => $m->read_at !== null,
                'dismissable' => true,
            ]);
        }

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
            foreach (GemLedger::where('character_id', $character->id)->latest('created_at')->limit(50)->get() as $g) {
                $items->push([
                    'type' => 'gem_transaction',
                    'icon' => $g->delta > 0 ? '💎' : '➖',
                    'title' => ($g->delta > 0 ? '+' : '').$g->delta.' gems',
                    'body' => Str::headline(explode(':', $g->reason)[0] ?? $g->reason)
                        .(str_contains($g->reason, ':') ? ' — '.str_replace('_', ' ', explode(':', $g->reason, 2)[1]) : ''),
                    'time' => $g->created_at,
                    'invite' => false,
                ]);
            }

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

    public function read(Request $request, Mail $mail)
    {
        abort_unless($mail->recipient_user_id === $request->user()->id, 403);

        $mail->update(['read_at' => $mail->read_at ?? now()]);

        return response()->json(['mail' => $mail]);
    }

    public function dismiss(Request $request, Mail $mail)
    {
        abort_unless($mail->recipient_user_id === $request->user()->id, 403);

        $mail->update(['dismissed_at' => now()]);

        return response()->json(['message' => 'Dismissed.']);
    }
}
