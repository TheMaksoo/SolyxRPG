<?php

namespace App\Services;

use App\Models\Character;
use App\Models\PvpLiveMatch;
use App\Models\PvpQueueEntry;
use Illuminate\Support\Facades\DB;

/** Queue/pairing logic for real player-vs-player matchmaking (see PvpController's queue/* endpoints and
 * the pvp:matchmaking-sweep console command). The queue (pvp_queue) is a lightweight pool, not a permanent
 * record — rows are deleted the moment a match is found or the player leaves. */
class PvpMatchmakingService
{
    public function __construct(private PvpLiveCombatService $combat = new PvpLiveCombatService()) {}

    public function activeMatchFor(int $characterId): ?PvpLiveMatch
    {
        return PvpLiveMatch::where('status', 'active')
            ->where(fn ($q) => $q->where('character_a_id', $characterId)->orWhere('character_b_id', $characterId))
            ->first();
    }

    public function queueEntryFor(int $characterId): ?PvpQueueEntry
    {
        return PvpQueueEntry::where('character_id', $characterId)->first();
    }

    public function leaveQueue(int $characterId): void
    {
        PvpQueueEntry::where('character_id', $characterId)->delete();
    }

    /** Backstop for players who close the tab/app while queued and never poll queue/status again (which
     * is what normally catches the same 5-minute timeout) — swept alongside sweep() so a stale queue row
     * can't sit forever inflating the pool. Returns how many entries were purged. */
    public function purgeStaleEntries(): int
    {
        return PvpQueueEntry::where('queued_at', '<=', now()->subMinutes(5))->delete();
    }

    /** Rating band (±) a queued character will accept an opponent from, widening the longer they've waited
     * so a rare high/low rating rarely waits forever — starts at ±100, +50 every 30s queued, capped at
     * ±1000 (by a few minutes in, effectively "anyone currently searching"). */
    public function bandFor(int $waitedSeconds): int
    {
        return min(1000, 100 + intdiv(max(0, $waitedSeconds), 30) * 50);
    }

    /**
     * Tries to pair $character with any other currently-queued character within rating band. Locks both
     * queue rows for the duration of the check (inside a transaction) so two concurrent callers — e.g. both
     * players' polls landing in the same second — can't double-match the same third opponent. Returns the
     * created match, or null if nobody suitable is waiting right now (the caller just stays queued).
     */
    public function attemptMatch(Character $character): ?PvpLiveMatch
    {
        return DB::transaction(function () use ($character) {
            $mine = PvpQueueEntry::where('character_id', $character->id)->lockForUpdate()->first();
            if (! $mine) {
                return null;
            }

            $band = $this->bandFor((int) now()->diffInSeconds($mine->queued_at, true));

            $candidate = PvpQueueEntry::where('character_id', '!=', $character->id)
                ->whereBetween('rating', [$mine->rating - $band, $mine->rating + $band])
                ->orderByRaw('ABS(rating - ?) asc', [$mine->rating])
                ->lockForUpdate()
                ->first();

            if (! $candidate) {
                return null;
            }

            $opponent = Character::find($candidate->character_id);
            if (! $opponent) {
                $candidate->delete();

                return null;
            }

            $match = $this->createLiveMatch($character, $opponent);

            $mine->delete();
            $candidate->delete();

            return $match;
        });
    }

    public function createLiveMatch(Character $a, Character $b): PvpLiveMatch
    {
        return PvpLiveMatch::create([
            'character_a_id' => $a->id,
            'character_b_id' => $b->id,
            'turn_character_id' => $a->id,
            'state_json' => [
                'a' => $this->combat->buildFighterState($a),
                'b' => $this->combat->buildFighterState($b),
            ],
            'log_json' => ["Match found: {$a->name} vs {$b->name}. {$a->name} goes first."],
            'status' => 'active',
            'last_action_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Backstop sweep (see PvpMatchmakingSweep console command, scheduled every minute): pairs up whoever's
     * pairable across the *entire* queue in one pass, in case both players' clients stopped polling before
     * attemptMatch() got a chance to run for either of them. Oldest-queued first so nobody camps at the
     * front of the line forever. Returns how many matches it created. */
    public function sweep(): int
    {
        $matched = 0;
        $skip = [];

        $entries = PvpQueueEntry::orderBy('queued_at')->get();

        foreach ($entries as $entry) {
            if (in_array($entry->id, $skip, true)) {
                continue;
            }

            $band = $this->bandFor((int) now()->diffInSeconds($entry->queued_at, true));
            $opponentEntry = $entries->first(fn ($e) => $e->id !== $entry->id
                && ! in_array($e->id, $skip, true)
                && abs($e->rating - $entry->rating) <= $band);

            if (! $opponentEntry) {
                continue;
            }

            $characterA = Character::find($entry->character_id);
            $characterB = Character::find($opponentEntry->character_id);

            if (! $characterA || ! $characterB) {
                // Stale row pointing at a deleted character — clear it out so it doesn't jam the queue forever.
                if (! $characterA) {
                    $entry->delete();
                    $skip[] = $entry->id;
                }
                if (! $characterB) {
                    $opponentEntry->delete();
                    $skip[] = $opponentEntry->id;
                }

                continue;
            }

            $this->createLiveMatch($characterA, $characterB);
            $entry->delete();
            $opponentEntry->delete();
            $skip[] = $entry->id;
            $skip[] = $opponentEntry->id;
            $matched++;
        }

        return $matched;
    }
}
