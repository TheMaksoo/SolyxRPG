<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterQuest;
use App\Models\Cosmetic;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\Quest;
use App\Services\AchievementService;
use App\Services\BattlePassService;
use App\Services\QuestService;
use Illuminate\Http\Request;

class QuestController extends Controller
{
    public function __construct(
        private BattlePassService $battlePass = new BattlePassService(),
        private QuestService $quests = new QuestService(),
        private AchievementService $achievements = new AchievementService(),
    ) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('quests', $request->user()), 403, 'Quests are not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        return response()->json($this->payload($character));
    }

    public function claim(Request $request, Quest $quest)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $state = $this->stateFor($character, $quest);

        if (! $state['completed']) {
            return response()->json(['message' => 'Quest not completed yet.'], 422);
        }
        if ($state['claimed']) {
            return response()->json(['message' => 'Already claimed.'], 422);
        }

        $reward = $quest->reward_json;
        if (! empty($reward['gold'])) {
            $character->increment('gold', $reward['gold']);
        }
        if (! empty($reward['gems'])) {
            $character->increment('gems', $reward['gems']);
            GemLedger::log($character, $reward['gems'], "quest_reward:{$quest->key}");
        }
        if (! empty($reward['xp'])) {
            $character->increment('xp', $reward['xp']);
        }

        CharacterQuest::updateOrCreate(
            ['character_id' => $character->id, 'quest_id' => $quest->id],
            ['claimed' => true, 'completed' => true]
        );
        $character->increment('quests_completed');
        $this->battlePass->addXp($character, 25);
        $this->achievements->check($character->fresh());
        $this->grantQuestTitle($character, $quest);

        return response()->json(array_merge($this->payload($character->fresh()), ['quest' => $quest]));
    }

    /** Some titles are earned for free by completing the quest of the same name, rather than bought with
     * gems — grant it here the moment that quest is claimed, instead of making the player buy what they
     * just earned. */
    private function grantQuestTitle(Character $character, Quest $quest): void
    {
        $cosmetic = Cosmetic::where('unlock_quest_key', $quest->key)->where('enabled', true)->first();
        if (! $cosmetic) {
            return;
        }

        if (! $character->cosmetics()->where('cosmetic_id', $cosmetic->id)->exists()) {
            $character->cosmetics()->create(['cosmetic_id' => $cosmetic->id]);
        }
    }

    /** Full quest-tab payload: every enabled quest visible to this character's class, plus the lifetime completed counter. */
    private function payload(Character $character): array
    {
        $quests = Quest::where('enabled', true)
            ->where(fn ($q) => $q->whereNull('class_key')->orWhere('class_key', $character->base_class))
            ->get()
            ->map(fn (Quest $quest) => $this->stateFor($character, $quest));

        return [
            'quests' => $quests,
            'quests_completed' => $character->quests_completed,
        ];
    }

    /** Live progress/completed/claimed for one quest. `level`-kind quests are computed straight from the
     * character's current level rather than an incrementing counter — there's nothing to "increment"
     * toward a level threshold, it's just true or not yet. Daily/weekly quests get lazily reset here if
     * their last completion fell outside the current day/week. */
    private function stateFor(Character $character, Quest $quest): array
    {
        $kind = $quest->goal_json['kind'] ?? null;

        if ($kind === 'level') {
            $target = $quest->goal_json['target'] ?? 1;
            $claimed = CharacterQuest::where('character_id', $character->id)->where('quest_id', $quest->id)->value('claimed') ?? false;

            return [
                'quest' => $quest,
                'progress' => min($target, $character->level),
                'completed' => $character->level >= $target,
                'claimed' => (bool) $claimed,
            ];
        }

        $progress = $character->quests()->where('quest_id', $quest->id)->first();
        if ($progress) {
            $progress = $this->quests->resetIfStale($progress, $quest);
        }

        return [
            'quest' => $quest,
            'progress' => $progress->progress ?? 0,
            'completed' => $progress->completed ?? false,
            'claimed' => $progress->claimed ?? false,
        ];
    }
}
