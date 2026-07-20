<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    /** The player's own tickets, so they can see what they've already filed, its status, and the
     * back-and-forth with the GM handling it. */
    public function index(Request $request)
    {
        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->with(['messages.sender:id,name,role'])
            ->latest()
            ->get();

        return response()->json(['tickets' => $tickets]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        $ticket = SupportTicket::create([
            'user_id' => $request->user()->id,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'priority' => $data['priority'] ?? 'normal',
        ]);

        return response()->json(['ticket' => $ticket], 201);
    }

    /** Lets the player reply on their own ticket. A reply on an already resolved/closed ticket bumps
     * it back to "pending" so it doesn't quietly sit closed while the player is still waiting on a reply. */
    public function sendMessage(Request $request, SupportTicket $ticket)
    {
        abort_unless($ticket->user_id === $request->user()->id, 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $ticket->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            $ticket->update(['status' => 'pending']);
        }

        return response()->json(['message' => $message->load('sender:id,name,role')], 201);
    }
}
