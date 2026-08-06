<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\TesterApprovedNotification;
use Illuminate\Http\Request;

class GmTesterController extends Controller
{
    /** List all pending tester applications — accounts that applied but haven't been approved/rejected. */
    public function index()
    {
        $applications = User::whereNotNull('tester_applied_at')
            ->whereNull('tester_approved_at')
            ->whereNull('tester_rejection_reason')
            ->orderBy('tester_applied_at')
            ->get(['id', 'name', 'email', 'tester_applied_at', 'role', 'created_at']);

        return response()->json(['applications' => $applications]);
    }

    /** Approve a tester application — grants the user access to the dev game immediately. */
    public function approve(Request $request, User $user)
    {
        abort_unless($user->isPendingTesterApproval(), 422, 'This account does not have a pending application.');

        $user->tester_approved_at = now();
        $user->role = 'tester';
        $user->is_tester = true;
        $user->save();

        AuditLog::record($request->user()->id, 'gm.tester.approve', 'users', $user->id);

        $user->notify(new TesterApprovedNotification());

        return response()->json(['message' => "{$user->name} has been approved as a tester."]);
    }

    /** Reject a tester application — the user sees the reason on their pending screen. */
    public function reject(Request $request, User $user)
    {
        abort_unless($user->isPendingTesterApproval(), 422, 'This account does not have a pending application.');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user->tester_rejection_reason = $data['reason'] ?? 'Your application was not accepted at this time.';
        $user->save();

        AuditLog::record($request->user()->id, 'gm.tester.reject', 'users', $user->id, ['reason' => $user->tester_rejection_reason]);

        return response()->json(['message' => "{$user->name}'s application has been rejected."]);
    }
}
