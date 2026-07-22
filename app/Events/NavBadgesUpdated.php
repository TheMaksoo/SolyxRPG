<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NavBadgesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public array $badges
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel("user.{$this->userId}.badges");
    }

    public function broadcastAs(): string
    {
        return 'badges.updated';
    }

    public function broadcastWith(): array
    {
        return ['badges' => $this->badges];
    }
}
