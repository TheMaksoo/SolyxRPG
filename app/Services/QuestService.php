<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterQuest;
use App\Models\Monster;
use App\Models\Quest;

/** Central place any action that can advance a quest (a battle win, a zone visit, a dungeon clear, a craft,
 * a skill unlock) reports through — keeps quest-progress bookkeeping out of the individual feature
 * controllers/services. */
class QuestService
{
    /** Bumps progress by 1 on every enabled quest matching `$kind` (or matching `boss_kill` against
     * `$monster`'s key, when a monster is involved). */
    public function progress(Character $character, string $kind, ?Monster $monster = null): void
    {
        // Quests that need extra context to match (a specific monster or skill) are excluded here —
        // they're only bumped by the more specific matcher (progressSkillUnlock, or the boss_kill branch below).
        $this->bump($character, fn (Quest $q) => (($q->goal_json['kind'] ?? null) === $kind && empty($q->goal_json['skill_key']))
            || ($monster && ($q->goal_json['kind'] ?? null) === 'boss_kill' && ($q->goal_json['monster_key'] ?? null) === $monster->key));
    }

    /** Bumps `skill_unlocked` quests scoped to the specific skill just unlocked (not ranked up). */
    public function progressSkillUnlock(Character $character, string $skillKey): void
    {
        $this->bump($character, fn (Quest $q) => ($q->goal_json['kind'] ?? null) === 'skill_unlocked'
            && ($q->goal_json['skill_key'] ?? null) === $skillKey);
    }

    /** Daily/weekly/monthly quests are meant to be repeatable — reset progress/completed/claimed once the
     * row's last update falls outside the current day (daily), ISO week (weekly), or calendar month
     * (monthly). `level`-kind quests are computed live from the character elsewhere and never stored, so
     * they never pass through here. */
    public function resetIfStale(CharacterQuest $progress, Quest $quest): CharacterQuest
    {
        if (! in_array($quest->type, ['daily', 'weekly', 'monthly'], true)) {
            return $progress;
        }
        if ($progress->progress <= 0 && ! $progress->completed && ! $progress->claimed) {
            return $progress;
        }

        $stale = match ($quest->type) {
            'daily' => ! $progress->updated_at->isToday(),
            'weekly' => $progress->updated_at->lt(now()->startOfWeek()),
            'monthly' => $progress->updated_at->lt(now()->startOfMonth()),
        };

        if (! $stale) {
            return $progress;
        }

        $progress->update(['progress' => 0, 'completed' => false, 'claimed' => false]);

        return $progress->fresh();
    }

    private function bump(Character $character, callable $matcher): void
    {
        $quests = Quest::where('enabled', true)->get()->filter($matcher);

        foreach ($quests as $quest) {
            $progress = CharacterQuest::firstOrCreate(
                ['character_id' => $character->id, 'quest_id' => $quest->id],
                ['progress' => 0]
            );
            $progress = $this->resetIfStale($progress, $quest);
            if ($progress->completed) {
                continue;
            }

            $target = $quest->goal_json['target'] ?? 1;
            $progress->increment('progress');
            if ($progress->fresh()->progress >= $target) {
                $progress->update(['completed' => true]);
            }
        }
    }
}
