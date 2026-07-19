<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class GmAuditLogController extends Controller
{
    public function index()
    {
        $logs = AuditLog::with('gm:id,name,email')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['logs' => $logs]);
    }
}
