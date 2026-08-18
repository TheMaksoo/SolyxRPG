<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterCosmetic;
use App\Models\Cosmetic;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\Quest;
use App\Services\LeaderboardService;
use Illuminate\Http\Request;

class CosmeticController extends Controller
{
    private const ACTIVE_COLUMN = ['title' => 'active_title_id', 'color' => 'active_color_id', 'banner' => 'active_banner_id', 'icon' => 'active_icon_id', 'frame' => 'active_frame_id'];

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('cosmetics', $request->user()), 403, 'Customize is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $owned = $character->cosmetics()->pluck('cosmetic_id')->flip();

        $cosmetics = Cosmetic::where('enabled', true)->get()->map(fn (Cosmetic $c) => [
            'cosmetic' => $c,
            'owned' => $owned->has($c->id),
            'active' => in_array($c->id, [$character->active_title_id, $character->active_color_id, $character->active_banner_id, $character->active_icon_id, $character->active_frame_id], true),
            'quest' => $c->unlock_quest_key ? Quest::where('key', $c->unlock_quest_key)->value('name') : null,
            'event' => $c->unlock_event,
            'event_label' => self::eventLabel($c->unlock_event),
        ])
            // Dynamic cosmetics (season leaderboard placements, PvP season rewards) are generated
            // per-character, not a fixed catalog — someone who hasn't earned one shouldn't see it listed
            // as a browsable "locked" entry at all, unlike gem-purchasable/quest-locked catalog items.
            ->reject(fn (array $row) => $row['cosmetic']->is_dynamic && ! $row['owned'])
            ->values();

        return response()->json([
            'cosmetics' => $cosmetics,
        ]);
    }

    /** Turns an unlock_event slug into real display text — without this, an event-locked cosmetic (PvP
     * season titles/frames, world-firsts, etc.) has no way to explain "how you'd earn this" in the
     * Customize UI, the same gap quest-locked cosmetics don't have (those show the quest's own name). */
    public static function eventLabel(?string $event): ?string
    {
        if (! $event) {
            return null;
        }

        if (preg_match('/^pvp_season_(\d+)_(platinum|diamond|master)$/', $event, $m)) {
            return 'Reach '.ucfirst($m[2])." by the end of PvP Season {$m[1]}";
        }

        if (preg_match('/^battle_pass_tier_(\d+)$/', $event, $m)) {
            return "Reach Battle Pass tier {$m[1]}";
        }

        if (preg_match('/^leaderboard_category_champion:([a-z_]+):(\d+)$/', $event, $m)) {
            $label = LeaderboardService::CATEGORIES[$m[1]]['label'] ?? $m[1];

            return "Finish #1 in {$label} — Season {$m[2]}";
        }

        if (preg_match('/^leaderboard_category_top10:([a-z_]+):(\d+)$/', $event, $m)) {
            $label = LeaderboardService::CATEGORIES[$m[1]]['label'] ?? $m[1];

            return "Finish top 10 in {$label} — Season {$m[2]}";
        }

        if (preg_match('/^leaderboard_trophies_champion:(\d+)$/', $event, $m)) {
            return "Finish #1 in PvP Trophies — Season {$m[1]}";
        }

        if (preg_match('/^leaderboard_trophies_top10_banner:(\d+)$/', $event, $m)) {
            return "Finish #2–10 in PvP Trophies — Season {$m[1]}";
        }

        if (preg_match('/^leaderboard_season_banner:([a-z_]+):(champion|top3|top10|top100):(\d+)$/', $event, $m)) {
            $label = LeaderboardService::CATEGORIES[$m[1]]['label'] ?? $m[1];
            $tierLabel = ['champion' => '#1', 'top3' => 'top 3', 'top10' => 'top 10', 'top100' => 'top 100'][$m[2]];

            return "Finish {$tierLabel} in {$label} — Season {$m[3]}";
        }

        return match ($event) {
            'tutorial_complete' => 'Complete the tutorial',
            default => ucfirst(str_replace('_', ' ', $event)),
        };
    }

    public function unlock(Request $request, Cosmetic $cosmetic)
    {
        abort_unless(FeatureFlag::gate('cosmetics', $request->user()), 403, 'Customize is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->cosmetics()->where('cosmetic_id', $cosmetic->id)->exists()) {
            return response()->json(['message' => 'Already unlocked.'], 422);
        }

        if ($cosmetic->unlock_quest_key) {
            $questName = Quest::where('key', $cosmetic->unlock_quest_key)->value('name') ?? 'the matching quest';

            return response()->json(['message' => "Earned by completing the quest \"{$questName}\" — cannot be bought."], 422);
        }
        if ($cosmetic->unlock_event || $cosmetic->category === 'battle_pass') {
            return response()->json(['message' => 'Earned automatically — cannot be bought.'], 422);
        }
        if ($character->user->gems < $cosmetic->cost_gems) {
            return response()->json(['message' => 'Not enough gems.'], 422);
        }
        if ($cosmetic->cost_gems > 0) {
            $character->user->decrement('gems', $cosmetic->cost_gems);
            GemLedger::log($character->user, -$cosmetic->cost_gems, "cosmetic_unlock:{$cosmetic->key}", $character);
        }

        $character->cosmetics()->create(['cosmetic_id' => $cosmetic->id]);

        return response()->json(['character' => $character->fresh()]);
    }

    public function equip(Request $request, Cosmetic $cosmetic)
    {
        abort_unless(FeatureFlag::gate('cosmetics', $request->user()), 403, 'Customize is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        if (! $character->cosmetics()->where('cosmetic_id', $cosmetic->id)->exists()) {
            return response()->json(['message' => 'Unlock this first.'], 422);
        }

        $column = self::ACTIVE_COLUMN[$cosmetic->type];
        $character->update([$column => $cosmetic->id]);

        return response()->json(['character' => $character->fresh(['activeTitle', 'activeColor', 'activeBanner', 'activeIcon', 'activeFrame'])]);
    }
}
