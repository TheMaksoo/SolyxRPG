<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow — turn-based PvP is the most latency-sensitive path being migrated; a queued
// dispatch risks sitting behind a slow/cron-driven queue worker on shared hosting.
class PvpMatchFound implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $userId, public int $matchId)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}.pvp");
    }

    public function broadcastAs(): string
    {
        return 'pvp.match-found';
    }

    public function broadcastWith(): array
    {
        return ['match_id' => $this->matchId];
    }
}
