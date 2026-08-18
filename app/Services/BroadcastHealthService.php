<?php

namespace App\Services;

use Pusher\Pusher;

/** Single place that answers "can we actually reach Pusher right now" — a real outbound API call, not
 * just a config-presence check. Shared by StatusController::websocket() (BetterStack HTTP monitor) and
 * the websocket:heartbeat scheduled command (BetterStack heartbeat monitor) so both surfaces agree on
 * what "healthy" means. */
class BroadcastHealthService
{
    public function pusherReachable(): bool
    {
        try {
            $pusher = new Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                config('broadcasting.connections.pusher.options'),
            );
            $pusher->get('/channels');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
