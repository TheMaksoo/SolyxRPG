<?php

namespace App\Console\Commands;

use App\Services\BroadcastHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Pings the BetterStack heartbeat URL only when Pusher is actually reachable (see
 * BroadcastHealthService) — skipping the ping on failure is the whole point: a heartbeat monitor
 * alerts when pings stop arriving, so a missed ping here means "websockets are down," not just
 * "cron stopped running." */
class PingWebsocketHeartbeat extends Command
{
    protected $signature = 'websocket:heartbeat';

    protected $description = 'Pings the BetterStack heartbeat URL if (and only if) Pusher is reachable.';

    public function handle(BroadcastHealthService $broadcastHealth): int
    {
        if (! $broadcastHealth->pusherReachable()) {
            $this->error('Pusher unreachable — skipping heartbeat ping so BetterStack alerts.');

            return self::SUCCESS;
        }

        $url = config('services.betterstack.heartbeat_url');
        if (! $url) {
            $this->error('BETTERSTACK_HEARTBEAT_URL is not configured — nothing to ping.');

            return self::SUCCESS;
        }

        Http::get($url);
        $this->info('Pusher reachable — heartbeat sent.');

        return self::SUCCESS;
    }
}
