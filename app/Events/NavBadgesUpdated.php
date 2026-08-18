<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Content-free "something changed, re-fetch" ping — deriving real badge counts lives in
// NavBadgeController's private methods, and duplicating that here just to push it over the wire
// isn't worth it when the frontend already has a working loadNavBadges({force:true}) to call instead.
class NavBadgesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $userId)
    {
    }

    // PrivateChannel, not Channel — routes/channels.php's authorization callback for this channel name
    // only ever runs for private/presence channels; a plain public Channel would skip that check
    // entirely and let anyone subscribe to any user's badge-update stream.
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}.badges");
    }

    public function broadcastAs(): string
    {
        return 'badges.updated';
    }
}
