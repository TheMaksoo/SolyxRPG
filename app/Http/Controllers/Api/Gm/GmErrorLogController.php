<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;

class GmErrorLogController extends Controller
{
    public function index()
    {
        $logs = ErrorLog::with('user:id,name')->latest('created_at')->limit(100)->get();

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
}
