<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Replaces what the old 2.5s queue-status poll used to catch inline: a client that stopped polling
// (or, now, never polled at all) still needs to learn its 5-minute-stale queue entry was purged by the
// pvp:matchmaking-sweep backstop, so the "Searching…" pill doesn't hang forever.
class PvpQueueTimedOut implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $userId)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}.pvp");
    }

    public function broadcastAs(): string
    {
        return 'pvp.queue-timeout';
    }
}
