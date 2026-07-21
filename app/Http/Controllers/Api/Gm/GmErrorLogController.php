<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;

class GmErrorLogController extends Controller
{
    public function index()
    {
        // Archived (i.e. "cleared") errors drop out of the list immediately rather than waiting for the
        // 7-day purge in CleanupStaleData — clearing is about decluttering the GM's view, not deleting
        // the record on the spot, so the row still exists (and is still counted in trend/by_class below)
        // until the retention window catches up to it.
        $logs = ErrorLog::with('user:id,name')->whereNull('archived_at')->latest('created_at')->limit(100)->get();

        $daily = ErrorLog::where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as day, count(*) as count')
            ->groupBy('day')
            ->pluck('count', 'day');

        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $trend[] = ['date' => $day, 'count' => (int) ($daily[$day] ?? 0)];
        }

        $byClass = ErrorLog::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('exception_class, count(*) as count')
            ->groupBy('exception_class')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'logs' => $logs,
            'total_24h' => ErrorLog::where('created_at', '>=', now()->subDay())->count(),
            'total_7d' => ErrorLog::where('created_at', '>=', now()->subDays(7))->count(),
            'trend' => $trend,
            'by_class' => $byClass,
        ]);
    }

    /** "Clear" a fixed error out of the log view — doesn't delete it outright, just hides it and starts
     * a 7-day clock (see CleanupStaleData) before it's purged for good, in case it turns out not to be
     * fixed after all and a GM needs to look back at it. */
    public function archive(ErrorLog $errorLog)
    {
        $errorLog->update(['archived_at' => now()]);

        return response()->json(['message' => 'Cleared.']);
    }
}
