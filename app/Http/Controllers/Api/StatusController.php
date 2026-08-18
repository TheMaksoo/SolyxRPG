<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BroadcastHealthService;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function __construct(private BroadcastHealthService $broadcastHealth = new BroadcastHealthService()) {}

    /** Public, unauthenticated health check — for an HTTP-style BetterStack monitor pointed directly at
     * this URL, if one gets added later alongside the heartbeat (see websocket:heartbeat). */
    public function websocket(Request $request)
    {
        if (! $this->broadcastHealth->pusherReachable()) {
            return response()->json(['status' => 'unreachable'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
