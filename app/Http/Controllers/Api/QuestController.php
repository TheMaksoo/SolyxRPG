<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterQuest;
use App\Models\Quest;
use Illuminate\Http\Request;

class QuestController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $quests = Quest::where('enabled', true)->get()->map(function (Quest $quest) use ($character) {
            $progress = $character->quests()->where('quest_id', $quest->id)->first();

            return [
                'quest' => $quest,
                'progress' => $progress->progress ?? 0,
                'completed' => $progress->completed ?? false,
                'claimed' => $progress->claimed ?? false,
            ];
        });

        return response()->json(['quests' => $quests]);
    }

    public function claim(Request $request, Quest $quest)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $progress = CharacterQuest::where('character_id', $character->id)->where('quest_id', $quest->id)->first();

        if (! $progress || ! $progress->completed) {
            return response()->json(['message' => 'Quest not completed yet.'], 422);
        }
        if ($progress->claimed) {
            return response()->json(['message' => 'Already claimed.'], 422);
        }

        $reward = $quest->reward_json;
        if (! empty($reward['gold'])) {
            $character->increment('gold', $reward['gold']);
        }
        if (! empty($reward['gems'])) {
            $character->increment('gems', $reward['gems']);
        }
        if (! empty($reward['xp'])) {
            $character->increment('xp', $reward['xp']);
        }

        $progress->update(['claimed' => true]);

        return response()->json(['character' => $character->fresh(), 'quest' => $quest]);
    }
}
