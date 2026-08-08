<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Changelog;
use App\Models\TesterBugReport;
use App\Models\TesterUpdateVote;
use Illuminate\Http\Request;

/**
 * Tester-facing Development page endpoints.
 *
 * - Voting (accept) on an update is a lightweight endorsement — GMs still decide when to push live.
 * - Bug reporting creates a TesterBugReport tied to the changelog entry; GMs then acknowledge it
 *   from the GM Console (which promotes it to a KnownBug).
 */
class DevelopmentController extends Controller
{
    /** Return all changelog entries visible to this user, with vote/bug-report counts and whether the current user voted. */
    public function index(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        $query = Changelog::orderByDesc('published_at')
            ->withCount(['bugReports', 'votes']);

        // Apply the same visibility rules as ChangelogController.
        if (! $user->isGm()) {
            if ($user->is_tester || $user->role === 'tester') {
                $query->whereIn('visibility', ['player', 'tester']);
            } else {
                $query->where('visibility', 'player');
            }
        }

        $entries = $query->get()->map(function (Changelog $entry) use ($userId) {
            $entry->user_voted = TesterUpdateVote::where('changelog_id', $entry->id)
                ->where('user_id', $userId)
                ->exists();

            return $entry;
        });

        return response()->json(['entries' => $entries]);
    }

    /** Cast or retract a tester vote on an update. */
    public function vote(Request $request, Changelog $changelog)
    {
        $userId = $request->user()->id;

        $existing = TesterUpdateVote::where('changelog_id', $changelog->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $existing->delete();
            $voted = false;
        } else {
            TesterUpdateVote::create(['changelog_id' => $changelog->id, 'user_id' => $userId]);
            $voted = true;
        }

        $voteCount = TesterUpdateVote::where('changelog_id', $changelog->id)->count();

        return response()->json(['voted' => $voted, 'votes_count' => $voteCount]);
    }

    /** Submit a bug report against a specific changelog entry. */
    public function reportBug(Request $request, Changelog $changelog)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $report = TesterBugReport::create([
            'changelog_id' => $changelog->id,
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => 'open',
        ]);

        AuditLog::record($request->user()->id, 'tester.bug_report.create', 'tester_bug_reports', $report->id);

        return response()->json(['message' => 'Bug report submitted.', 'report' => $report], 201);
    }
}
