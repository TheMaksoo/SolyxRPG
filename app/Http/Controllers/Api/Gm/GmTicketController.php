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
        return response()->json(['tickets' => SupportTicket::with('user', 'assignedGm')->latest()->get()]);
    }

    public function resolve(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'resolved', 'closed'])]]);

        $ticket->update(['status' => $data['status'], 'assigned_gm_id' => $request->user()->id]);
        AuditLog::record($request->user()->id, 'gm.ticket.resolve', 'support_tickets', $ticket->id, $data);

        return response()->json(['ticket' => $ticket->fresh()]);
    }
}
