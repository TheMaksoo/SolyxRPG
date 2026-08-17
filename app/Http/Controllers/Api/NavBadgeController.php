<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Friendship;
use App\Models\PartyInvite;
use App\Models\Quest;
use App\Services\BattlePassService;
use App\Services\QuestService;
use Illuminate\Http\Request;

/** One aggregated call for the "!" badges that genuinely need server-side data the client doesn't
 * otherwise have: claimable quests, unclaimed Battle Pass tiers, pending party invites, pending friend
 * requests. Every other badge that used to live here (daily reward, crafting, dungeon/PvP attempts,
 * unread mail, referral nudge) is now computed entirely client-side in GameLayout.vue from data the
 * app already loads for other reasons (the character payload, the crafting queue store, the inbox
 * fetch) — cutting both this endpoint's query cost and how much the sidebar depends on it. */
class NavBadgeController extends Controller
{
    public function __construct(
        private QuestService $quests = new QuestService(),
        private BattlePassService $battlePass = new BattlePassService(),
    ) {
    }

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        return response()->json([
            'quests' => $this->unclaimedQuestCount($character),
            'battle_pass' => $this->unclaimedBattlePassCount($character),
            'party_invites' => PartyInvite::where('character_id', $character->id)->count(),
            'friend_requests' => Friendship::where('addressee_id', $character->id)->where('status', 'pending')->count(),
        ]);
    }

    /** Mirrors QuestController::stateFor()'s completed/claimed logic, counted rather than fully serialized.
     * Fetches every CharacterQuest row for this character once up front and keys it by quest_id, instead
     * of the previous one-query-per-quest lookup — this endpoint backs the sidebar badge count rendered
     * on every page load, so an N+1 here means N extra queries on every single page navigation. */
    private function unclaimedQuestCount(Character $character): int
    {
        $quests = Quest::where('enabled', true)
            ->where(fn ($q) => $q->whereNull('class_key')->orWhere('class_key', $character->base_class))
            ->get();

        $progressByQuest = $character->quests()->get()->keyBy('quest_id');

        $count = 0;
        foreach ($quests as $quest) {
            $kind = $quest->goal_json['kind'] ?? null;
            $progress = $progressByQuest->get($quest->id);

            if ($kind === 'level') {
                $target = $quest->goal_json['target'] ?? 1;
                $claimed = $progress->claimed ?? false;
                if ($character->level >= $target && ! $claimed) {
                    $count++;
                }

                continue;
            }

            if ($progress) {
                $progress = $this->quests->resetIfStale($progress, $quest);
            }
            if (($progress->completed ?? false) && ! ($progress->claimed ?? false)) {
                $count++;
            }
        }

        return $count;
    }

    private function unclaimedBattlePassCount(Character $character): int
    {
        $pass = $this->battlePass->passFor($character);
        $freeClaimed = $pass->claimed_free_tiers ?? [];
        $premiumClaimed = $pass->claimed_premium_tiers ?? [];

        $count = 0;
        for ($tier = 1; $tier <= $pass->tier; $tier++) {
            if (! in_array($tier, $freeClaimed, true)) {
                $count++;
            }
            if ($pass->premium && ! in_array($tier, $premiumClaimed, true)) {
                $count++;
            }
        }

        return $count;
    }
}
