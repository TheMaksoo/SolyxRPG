<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Changelog;
use App\Models\KnownBug;
use App\Models\TesterBugReport;
use Illuminate\Http\Request;

/**
 * GM Console — Development tab.
 *
 * Combines tester-application management (previously GmTesterController) with
 * update-approval management (push-live) and bug-report acknowledgement.
 */
class GmDevelopmentController extends Controller
{
    // ── Update Approvals ────────────────────────────────────────────────────

    /** All changelog entries, newest first, with tester vote/bug counts. */
    public function updateApprovals()
    {
        $entries = Changelog::orderByDesc('published_at')
            ->withCount(['votes', 'bugReports'])
            ->with(['bugReports' => fn ($q) => $q->with('user')->orderByDesc('created_at')])
            ->get();

        return response()->json(['entries' => $entries]);
    }

    /** GM pushes a changelog entry live — marks pushed_live_at. */
    public function pushLive(Request $request, Changelog $changelog)
    {
        abort_if($changelog->pushed_live_at !== null, 422, 'This update has already been pushed live.');

        $changelog->pushed_live_at = now();
        $changelog->save();

        AuditLog::record($request->user()->id, 'gm.development.push_live', 'changelogs', $changelog->id);

        return response()->json(['message' => "v{$changelog->version} pushed live.", 'entry' => $changelog]);
    }

    /** GM acknowledges a tester bug report — promotes it to a KnownBug entry in one click. */
    public function acknowledgeBugReport(Request $request, TesterBugReport $report)
    {
        abort_if($report->status === 'acknowledged', 422, 'This report has already been acknowledged.');

        $data = $request->validate([
            'area' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', 'string', 'in:minor,major,critical'],
        ]);

        $bug = KnownBug::create([
            'title' => $report->title,
            'description' => $report->description,
            'area' => $data['area'] ?? 'General',
            'severity' => $data['severity'] ?? 'minor',
            'status' => 'reported',
        ]);

        $report->status = 'acknowledged';
        $report->known_bug_id = $bug->id;
        $report->save();

        AuditLog::record($request->user()->id, 'gm.development.acknowledge_bug', 'tester_bug_reports', $report->id, ['known_bug_id' => $bug->id]);

        return response()->json(['message' => 'Bug acknowledged and added to Known Bugs.', 'bug' => $bug]);
    }
}
