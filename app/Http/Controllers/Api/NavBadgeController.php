<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterQuest;
use App\Models\CraftingJob;
use App\Models\Friendship;
use App\Models\Mail;
use App\Models\PartyInvite;
use App\Models\Quest;
use App\Services\BattlePassService;
use App\Services\QuestService;
use Illuminate\Http\Request;

/** One aggregated call for every "!" badge count the sidebar shows — claimable quests, unclaimed Battle
 * Pass tiers, an unclaimed daily reward, pending party invites, pending friend requests, unread mail,
 * finished crafting jobs, and remaining PvP/dungeon attempts. Kept as its own lightweight read-only
 * endpoint rather than bolting badge counts onto each feature's own controller, so the sidebar (rendered
 * on every page) only ever needs one request. */
class NavBadgeController extends Controller
{
    public function __construct(
        private QuestService $quests = new QuestService(),
        private BattlePassService $battlePass = new BattlePassService(),
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $character = $user->character;
        abort_unless($character, 404);

        return response()->json([
            'quests' => $this->unclaimedQuestCount($character),
            'battle_pass' => $this->unclaimedBattlePassCount($character),
            'daily' => $this->dailyClaimable($character) ? 1 : 0,
            'party_invites' => PartyInvite::where('character_id', $character->id)->count(),
            'friend_requests' => Friendship::where('addressee_id', $character->id)->where('status', 'pending')->count(),
            'mail' => Mail::where('recipient_user_id', $user->id)->whereNull('dismissed_at')->whereNull('read_at')->count(),
            'crafting' => CraftingJob::where('character_id', $character->id)->whereNull('collected_at')->where('completes_at', '<=', now())->count(),
            'dungeons' => $this->attemptsRemaining($character->dungeon_attempts_used, $character->dungeon_attempts_reset_at, 3 + $user->vipDungeonBonusAttempts()) > 0 ? 1 : 0,
            'pvp' => $this->attemptsRemaining($character->pvp_attempts_used, $character->pvp_attempts_reset_at, 10 + $user->vipPvpBonusAttempts()) > 0 ? 1 : 0,
        ]);
    }

    /** Mirrors the reset-then-check logic each attempt-consuming controller does, without writing —
     * this endpoint is read-only, so a past reset time just means "treat used as 0" rather than saving it. */
    private function attemptsRemaining(?int $used, $resetAt, int $max): int
    {
        $used = (! $resetAt || $resetAt->isPast()) ? 0 : ($used ?? 0);

        return max(0, $max - $used);
    }

    /** Mirrors QuestController::stateFor()'s completed/claimed logic, counted rather than fully serialized. */
    private function unclaimedQuestCount(Character $character): int
    {
        $quests = Quest::where('enabled', true)
            ->where(fn ($q) => $q->whereNull('class_key')->orWhere('class_key', $character->base_class))
            ->get();

        $count = 0;
        foreach ($quests as $quest) {
            $kind = $quest->goal_json['kind'] ?? null;

            if ($kind === 'level') {
                $target = $quest->goal_json['target'] ?? 1;
                $claimed = CharacterQuest::where('character_id', $character->id)->where('quest_id', $quest->id)->value('claimed') ?? false;
                if ($character->level >= $target && ! $claimed) {
                    $count++;
                }

                continue;
            }

            $progress = $character->quests()->where('quest_id', $quest->id)->first();
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

    private function dailyClaimable(Character $character): bool
    {
        $claim = $character->dailyClaim;

        return ! ($claim && $claim->last_claim_date && $claim->last_claim_date->isToday());
    }
}
