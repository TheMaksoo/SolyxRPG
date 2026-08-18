<?php

namespace App\Services;

use App\Events\PvpMatchFound;
use App\Events\PvpQueueTimedOut;
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

    /** Daily attempts only ever deplete when a real match starts (see createLiveMatch()) — joining or
     * waiting in the queue never costs anything, so a long search never punishes the player for it. */
    private function resetPvpAttemptsIfNeeded(Character $character): void
    {
        if (! $character->pvp_attempts_reset_at || $character->pvp_attempts_reset_at->isPast()) {
            $character->pvp_attempts_used = 0;
            $character->pvp_attempts_reset_at = now()->endOfDay();
        }
    }

    /** Not persisted — mirrors DungeonController::index()'s read-only reset-window check, used both to
     * gate queueJoin() up front and to report a used/max pair on the lobby (see PvpController::index()). */
    public function pvpAttemptsSnapshot(Character $character): array
    {
        $this->resetPvpAttemptsIfNeeded($character);
        $max = 10 + $character->user->vipPvpBonusAttempts();

        return ['used' => $character->pvp_attempts_used, 'max' => $max];
    }

    public function remainingPvpAttempts(Character $character): int
    {
        $snapshot = $this->pvpAttemptsSnapshot($character);

        return max(0, $snapshot['max'] - $snapshot['used']);
    }

    private function consumePvpAttempt(Character $character): void
    {
        $this->resetPvpAttemptsIfNeeded($character);
        $character->pvp_attempts_used++;
        $character->save();
    }

    /** Backstop for players who close the tab/app while queued (or, now that queue/status is no longer
     * polled on a timer, simply anyone whose queue entry goes stale) — swept alongside sweep() so a
     * stale queue row can't sit forever inflating the pool. Pushes a timeout event to each affected
     * player so their "Searching…" pill doesn't hang forever waiting for a poll that no longer happens.
     * Returns how many entries were purged. */
    public function purgeStaleEntries(): int
    {
        $stale = PvpQueueEntry::where('queued_at', '<=', now()->subMinutes(5))->get();
        if ($stale->isEmpty()) {
            return 0;
        }

        $characterIds = $stale->pluck('character_id');
        PvpQueueEntry::whereIn('id', $stale->pluck('id'))->delete();

        $userIds = Character::whereIn('id', $characterIds)->pluck('user_id');
        foreach ($userIds as $userId) {
            broadcast(new PvpQueueTimedOut($userId));
        }

        return $stale->count();
    }

    /** Rating band (±) a queued character will accept an opponent from, widening the longer they've waited
     * so a rare high/low rating rarely waits forever — starts at ±100, +100 every 30s queued, capped at
     * ±1000 (reached well before queueStatus()'s 5-minute search timeout, so two valid, level-appropriate
     * opponents with a big rating gap — common with a small population — actually get to pair up instead
     * of both timing out having "found no rival", which is what a slower +50/30s widening rate used to do). */
    public function bandFor(int $waitedSeconds): int
    {
        return min(1000, 100 + intdiv(max(0, $waitedSeconds), 30) * 100);
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

            // rating is an unsignedInteger column — casting to SIGNED before subtracting avoids MySQL
            // strict mode erroring on an unsigned-arithmetic underflow whenever a candidate's rating is
            // lower than $mine->rating (a very ordinary case, not an edge case).
            $candidate = PvpQueueEntry::where('character_id', '!=', $character->id)
                ->whereBetween('rating', [max(0, $mine->rating - $band), $mine->rating + $band])
                ->orderByRaw('ABS(CAST(rating AS SIGNED) - ?) asc', [$mine->rating])
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
        // Attempts are consumed here, and only here — the single choke point every match creation path
        // (queueJoin's immediate pairing, queueStatus's poll-driven pairing, and the background sweep)
        // funnels through, so a fighter is charged exactly once per real battle regardless of which path
        // matched them.
        $this->consumePvpAttempt($a);
        $this->consumePvpAttempt($b);

        $match = PvpLiveMatch::create([
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

        // Single choke point every matching path (queueJoin's immediate pairing, queueStatus's
        // poll-driven pairing, and the scheduled sweep) funnels through — nothing is missed.
        broadcast(new PvpMatchFound($a->user->id, $match->id));
        broadcast(new PvpMatchFound($b->user->id, $match->id));

        return $match;
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
