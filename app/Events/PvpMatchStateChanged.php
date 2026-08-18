<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Content-free ping, same reasoning as NavBadgesUpdated: PvpController::matchPayload() is
// viewer-relative (me/opponent, live potions, is_my_turn) and Pusher can't send two different
// payloads to two subscribers of one channel event, so each client re-derives its own correctly
// shaped payload via the existing GET /pvp/live/{matchId} instead of duplicating that logic here.
class PvpMatchStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $matchId)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("pvp-match.{$this->matchId}");
    }

    public function broadcastAs(): string
    {
        return 'pvp.state-changed';
    }
}
