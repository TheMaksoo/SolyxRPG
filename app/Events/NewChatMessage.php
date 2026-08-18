<?php

namespace App\Events;

use App\Models\WorldMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow (not ShouldBroadcast) — production's queue has no confirmed persistent worker
// on shared hosting, so a queued broadcast could sit for up to a minute. Firing synchronously inside
// the already-mutating send() request costs ~100-300ms, imperceptible for a chat send.
class NewChatMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public WorldMessage $message)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('world-chat');
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
