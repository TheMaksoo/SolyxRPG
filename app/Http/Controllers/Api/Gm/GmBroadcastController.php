<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class GmBroadcastController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);

        $announcement = Announcement::create([
            'gm_id' => $request->user()->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        AuditLog::record($request->user()->id, 'gm.broadcast', 'announcements', $announcement->id);

        return response()->json(['announcement' => $announcement], 201);
    }
}
