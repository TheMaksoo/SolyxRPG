<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\PvpLiveMatch;
use App\Models\PvpMatch;
use App\Models\PvpQueueEntry;
use App\Models\PvpRecord;
use App\Services\PvpLiveCombatService;
use App\Services\PvpMatchmakingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PvpController extends Controller
{
    /** Opponents more than this many levels away (either direction) never appear in the challenge list —
     * mirrors the level-band pattern used for monster/dungeon matching elsewhere in the game. */
    private const PVP_LEVEL_BAND = 10;

    public function __construct(
        private PvpMatchmakingService $matchmaking = new PvpMatchmakingService(),
        private PvpLiveCombatService $combat = new PvpLiveCombatService(),
    ) {
    }

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('pvp', $request->user()), 403, 'PvP Arena is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $record = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);

        $allRatings = PvpRecord::pluck('rating')->all();
        // Hybrid rank merges the old fixed-tier ladder and the percentile bracket into one label: your
        // rank name is whichever tier your live percentile against the current ladder lands in, and the
        // outright #1 player gets a distinct crown on top of it. See PvpRecord::hybridRank() for cutoffs.
        $hybridRank = PvpRecord::hybridRank($record->rating, $allRatings);

        // Only ever list rivals who could actually accept the challenge right now and make for a fair
        // fight: a character whose owner has lost PvP access (feature flag toggled off for them, e.g. a
        // tester-only rollout) would otherwise show up as challengeable and then 403 the moment you hit
        // Challenge; a character outside your hybrid tier or level band is either a guaranteed stomp or a
        // guaranteed loss, not a real match.
        $opponents = Character::where('id', '!=', $character->id)
            ->whereBetween('level', [max(1, $character->level - self::PVP_LEVEL_BAND), $character->level + self::PVP_LEVEL_BAND])
            ->with(['pvpRecord', 'user'])
            ->limit(60)
            ->get()
            ->filter(fn (Character $c) => FeatureFlag::gate('pvp', $c->user))
            ->map(fn (Character $c) => [
                'character' => $c,
                'rating' => $c->pvpRecord->rating ?? 1000,
            ])
            ->filter(fn ($row) => PvpRecord::hybridRank($row['rating'], $allRatings)['base_name'] === $hybridRank['base_name'])
            ->take(20)
            ->values();

        $history = PvpMatch::where('character_id', $character->id)
            ->with('opponent')
            ->latest('created_at')
            ->limit(10)
            ->get();

        $activeMatch = $this->matchmaking->activeMatchFor($character->id);
        $queueEntry = $this->matchmaking->queueEntryFor($character->id);

        return response()->json([
            'record' => $record,
            'rank' => $hybridRank,
            'rank_progress' => PvpRecord::hybridProgress($hybridRank['percentile']),
            'rank_ladder' => array_map(fn ($t) => [
                'name' => $t['name'],
                'color' => $t['color'],
                'is_current' => $t['name'] === $hybridRank['base_name'],
            ], PvpRecord::PVP_TIERS),
            'opponents' => $opponents->map(fn ($row) => [
                ...$row,
                'rank' => PvpRecord::hybridRank($row['rating'], $allRatings),
                'difficulty' => match (true) {
                    $row['rating'] > $record->rating + 75 => 'Hard',
                    $row['rating'] < $record->rating - 75 => 'Easy',
                    default => 'Medium',
                },
            ]),
            'history' => $history,
            'active_match_id' => $activeMatch?->id,
            'queued' => $queueEntry !== null,
        ]);
    }

    // ---- Matchmaking queue ----

    /** Joins the matchmaking queue and immediately tries to pair with anyone else already waiting within
     * rating band. Rejects if the player is already mid-match or already queued — "PvP should only be able
     * to fight people that can fight" cashes out here: you can't be paired into a queue slot while you're
     * stuck in an existing match. */
    public function queueJoin(Request $request)
    {
        abort_unless(FeatureFlag::gate('pvp', $request->user()), 403, 'PvP Arena is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        abort_if($this->matchmaking->activeMatchFor($character->id), 422, 'You are already in an active PvP match.');
        abort_if($this->matchmaking->queueEntryFor($character->id), 422, 'You are already searching for a match.');

        $record = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);
        PvpQueueEntry::create([
            'character_id' => $character->id,
            'rating' => $record->rating,
            'queued_at' => now(),
        ]);

        $match = $this->matchmaking->attemptMatch($character);
        if ($match) {
            return response()->json(['status' => 'matched', 'match_id' => $match->id]);
        }

        return response()->json(['status' => 'searching', 'queued_at' => now()->toIso8601String(), 'elapsed_seconds' => 0]);
    }

    public function queueLeave(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $this->matchmaking->leaveQueue($character->id);

        return response()->json(['status' => 'left']);
    }

    /** Polled every few seconds by the frontend while searching. Also attempts the same pairing logic as
     * queueJoin() on every call, so two players who joined moments apart still get paired the next time
     * either of them polls — that's what makes matching feel near-instant without a background job. */
    public function queueStatus(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $activeMatch = $this->matchmaking->activeMatchFor($character->id);
        if ($activeMatch) {
            return response()->json(['status' => 'matched', 'match_id' => $activeMatch->id]);
        }

        $entry = $this->matchmaking->queueEntryFor($character->id);
        if (! $entry) {
            return response()->json(['status' => 'idle']);
        }

        // Nobody suitable showed up in 5 minutes — stop searching instead of leaving the player queued
        // forever; "no rival nearby" is a real, honest answer rather than an endless spinner.
        if (now()->diffInSeconds($entry->queued_at) >= 300) {
            $entry->delete();

            return response()->json(['status' => 'timeout']);
        }

        $match = $this->matchmaking->attemptMatch($character);
        if ($match) {
            return response()->json(['status' => 'matched', 'match_id' => $match->id]);
        }

        return response()->json([
            'status' => 'searching',
            'queued_at' => $entry->queued_at->toIso8601String(),
            'elapsed_seconds' => now()->diffInSeconds($entry->queued_at),
        ]);
    }

    /** Direct challenge entry point — creates a live match against a specific opponent right away instead
     * of going through the queue-pairing step. Kept as the "Challenge" button's target since players
     * already know that entry point from the old instant-sim arena. */
    public function challenge(Request $request, Character $opponent)
    {
        abort_unless(FeatureFlag::gate('pvp', $request->user()), 403, 'PvP Arena is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->id === $opponent->id, 422, 'Cannot challenge yourself.');

        abort_if($this->matchmaking->activeMatchFor($character->id), 422, 'You are already in an active PvP match.');
        abort_if($this->matchmaking->activeMatchFor($opponent->id), 422, 'That player is already in a match.');

        $this->matchmaking->leaveQueue($character->id);

        $match = $this->matchmaking->createLiveMatch($character, $opponent);
        $character->update(['last_action' => "PvP vs {$opponent->name}"]);
        $opponent->update(['last_action' => "PvP vs {$character->name}"]);

        return response()->json(['status' => 'matched', 'match_id' => $match->id]);
    }

    // ---- Live match play ----

    public function liveShow(Request $request, PvpLiveMatch $match)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $side = $match->sideFor($character->id);
        abort_if($side === null, 403, 'This match belongs to different characters.');

        return response()->json($this->matchPayload($match, $character->id));
    }

    public function liveAction(Request $request, PvpLiveMatch $match)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['attack', 'skill'])],
            'skill_id' => ['nullable', 'exists:skills,id'],
        ]);

        $character = $request->user()->character;
        abort_unless($character, 404);

        $side = $match->sideFor($character->id);
        abort_if($side === null, 403, 'This match belongs to different characters.');
        abort_if($match->status !== 'active', 422, 'This match has already ended.');
        abort_if($match->turn_character_id !== $character->id, 422, "It's not your turn.");

        $defSide = $side === 'a' ? 'b' : 'a';
        $state = $match->state_json;
        $atk = $state[$side];
        $def = $state[$defSide];

        $log = $this->combat->resolveTurn($atk, $def, $data['type'], $data['skill_id'] ?? null, $match->log_json ?? []);

        $state[$side] = $atk;
        $state[$defSide] = $def;

        if ($def['hp'] <= 0) {
            $winnerCharacterId = $atk['character_id'];
            $loserCharacterId = $def['character_id'];
            $log[] = "{$atk['name']} wins!";

            $match->state_json = $state;
            $match->log_json = $log;
            $match->status = 'finished';
            $match->winner_character_id = $winnerCharacterId;
            $match->last_action_at = now();
            $match->save();

            $reward = $this->combat->resolveMatchWin($match, $winnerCharacterId, $loserCharacterId, $log);
            $state = $match->state_json;
            $state['reward'] = $reward;
            $match->state_json = $state;
            $match->save();

            return response()->json($this->matchPayload($match->fresh(), $character->id));
        }

        $match->state_json = $state;
        $match->log_json = $log;
        $match->turn_character_id = $match->opponentIdFor($character->id);
        $match->last_action_at = now();
        $match->save();

        return response()->json($this->matchPayload($match, $character->id));
    }

    /** Either player can concede early — the other player wins, with the same bookkeeping as a real win. */
    public function liveForfeit(Request $request, PvpLiveMatch $match)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $side = $match->sideFor($character->id);
        abort_if($side === null, 403, 'This match belongs to different characters.');
        abort_if($match->status !== 'active', 422, 'This match has already ended.');

        $winnerCharacterId = $match->opponentIdFor($character->id);
        $loserCharacterId = $character->id;

        $log = $match->log_json ?? [];
        $log[] = "{$character->name} forfeits the match.";

        $match->status = 'forfeited';
        $match->winner_character_id = $winnerCharacterId;
        $match->log_json = $log;
        $match->last_action_at = now();
        $match->save();

        $reward = $this->combat->resolveMatchWin($match, $winnerCharacterId, $loserCharacterId, $log);
        $state = $match->state_json;
        $state['reward'] = $reward;
        $match->state_json = $state;
        $match->save();

        return response()->json($this->matchPayload($match->fresh(), $character->id));
    }

    /** Shapes a live match for one specific viewer: 'me'/'opponent' rather than 'a'/'b' so the frontend
     * never has to know which literal side it is, plus whose turn it is and (once finished) the reward
     * summary stashed in state_json by resolveMatchWin() — available to both players on every subsequent
     * poll, not only to whichever client happened to submit the finishing action/forfeit. */
    private function matchPayload(PvpLiveMatch $match, int $viewerCharacterId): array
    {
        $state = $match->state_json;
        $mySide = $match->sideFor($viewerCharacterId);
        $oppSide = $mySide === 'a' ? 'b' : 'a';

        return [
            'id' => $match->id,
            'status' => $match->status,
            'turn_character_id' => $match->turn_character_id,
            'is_my_turn' => $match->status === 'active' && $match->turn_character_id === $viewerCharacterId,
            'winner_character_id' => $match->winner_character_id,
            'i_won' => $match->winner_character_id !== null && $match->winner_character_id === $viewerCharacterId,
            'last_action_at' => $match->last_action_at,
            'log' => $match->log_json,
            'me' => $state[$mySide] ?? null,
            'opponent' => $state[$oppSide] ?? null,
            'reward' => $state['reward'] ?? null,
        ];
    }
}
