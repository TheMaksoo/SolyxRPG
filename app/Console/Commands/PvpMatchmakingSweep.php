<?php

namespace App\Console\Commands;

use App\Services\PvpMatchmakingService;
use Illuminate\Console\Command;

/** Backstop matchmaking pass over the whole PvP queue (see routes/console.php — runs every minute). The
 * queue/join and queue/status endpoints already attempt a pairing on every call, which is what makes
 * matchmaking feel instant; this sweep exists purely so two players who both stop polling at an
 * inconvenient moment still eventually get paired. */
class PvpMatchmakingSweep extends Command
{
    protected $signature = 'pvp:matchmaking-sweep';

    protected $description = 'Backstop matchmaking pass over the PvP queue, in case both players\' clients stopped polling before an instant match attempt could pair them.';

    public function handle(PvpMatchmakingService $matchmaking): int
    {
        $matched = $matchmaking->sweep();
        $this->info("{$matched} PvP match(es) created from the queue backstop sweep.");

        return self::SUCCESS;
    }
}
