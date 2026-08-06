<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * On the dev environment (TESTER_REGISTRATION=true), block access to game routes for accounts that
 * haven't been approved by a GM yet. GMs and Owners always bypass this check.
 *
 * Approved users (tester_approved_at is set) pass through immediately.
 * Pending users (applied but not yet approved/rejected) receive a 403 with `pending_approval: true`.
 * Rejected users receive a 403 with `tester_rejected: true` and the reason.
 * Unapproved users on live (TESTER_REGISTRATION=false) also pass — the gate only activates on dev.
 */
class EnsureTesterApproved
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Only enforce on environments where tester approval is required.
        if (! config('app.tester_registration', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // GMs and Owners always have access.
        if ($user->isGm()) {
            return $next($request);
        }

        // Approved testers pass through.
        if ($user->hasTesterAccess()) {
            return $next($request);
        }

        // Pending — applied but not yet reviewed.
        if ($user->isPendingTesterApproval()) {
            return response()->json([
                'message' => 'Your tester application is awaiting GM approval.',
                'pending_approval' => true,
            ], 403);
        }

        // Rejected.
        if ($user->isTesterRejected()) {
            return response()->json([
                'message' => $user->tester_rejection_reason ?? 'Your application was not accepted.',
                'tester_rejected' => true,
                'rejection_reason' => $user->tester_rejection_reason,
            ], 403);
        }

        // Account exists but never applied — shouldn't normally happen on dev since register()
        // sets tester_applied_at automatically, but guard it just in case (e.g. OAuth sign-ups).
        return response()->json([
            'message' => 'This environment is restricted to approved testers.',
            'pending_approval' => false,
            'requires_application' => true,
        ], 403);
    }
}
