<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GmTicketController extends Controller
{
    public function index()
    {
        return response()->json(['tickets' => SupportTicket::with('user', 'assignedGm', 'messages.sender:id,name,role')->latest()->get()]);
    }

    public function resolve(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'resolved', 'closed'])]]);

        $ticket->update(['status' => $data['status'], 'assigned_gm_id' => $request->user()->id]);
        AuditLog::record($request->user()->id, 'gm.ticket.resolve', 'support_tickets', $ticket->id, $data);

        return response()->json(['ticket' => $ticket->fresh()]);
    }

    /** GM reply on a ticket's chat thread. Sending a reply also claims the ticket (assigned_gm_id) and,
     * if it was still untouched, bumps it to "pending" so the console reflects that someone's on it. */
    public function sendMessage(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $ticket->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $ticket->update([
            'assigned_gm_id' => $ticket->assigned_gm_id ?? $request->user()->id,
            'status' => $ticket->status === 'open' ? 'pending' : $ticket->status,
        ]);

        AuditLog::record($request->user()->id, 'gm.ticket.message', 'support_tickets', $ticket->id, ['body' => $data['body']]);

        return response()->json(['message' => $message->load('sender:id,name,role')], 201);
    }
}
