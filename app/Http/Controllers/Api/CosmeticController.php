<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterCosmetic;
use App\Models\Cosmetic;
use App\Models\GemLedger;
use App\Models\Quest;
use Illuminate\Http\Request;

class CosmeticController extends Controller
{
    private const ACTIVE_COLUMN = ['title' => 'active_title_id', 'color' => 'active_color_id', 'banner' => 'active_banner_id', 'icon' => 'active_icon_id'];

    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $isTester = $request->user()->isTester();
        $owned = $character->cosmetics()->pluck('cosmetic_id')->flip();

        $cosmetics = Cosmetic::where('enabled', true)->get()->map(fn (Cosmetic $c) => [
            'cosmetic' => $c,
            'owned' => $isTester || $owned->has($c->id),
            'active' => in_array($c->id, [$character->active_title_id, $character->active_color_id, $character->active_banner_id, $character->active_icon_id], true),
            'quest' => $c->unlock_quest_key ? Quest::where('key', $c->unlock_quest_key)->value('name') : null,
            'event' => $c->unlock_event,
        ]);

        return response()->json([
            'cosmetics' => $cosmetics,
            'is_tester' => $isTester,
        ]);
    }

    public function unlock(Request $request, Cosmetic $cosmetic)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        if ($character->cosmetics()->where('cosmetic_id', $cosmetic->id)->exists()) {
            return response()->json(['message' => 'Already unlocked.'], 422);
        }

        if (! $request->user()->isTester()) {
            if ($cosmetic->unlock_quest_key) {
                $questName = Quest::where('key', $cosmetic->unlock_quest_key)->value('name') ?? 'the matching quest';

                return response()->json(['message' => "Earned by completing the quest \"{$questName}\" — cannot be bought."], 422);
            }
            if ($cosmetic->unlock_event) {
                return response()->json(['message' => 'Earned automatically — cannot be bought.'], 422);
            }
            if ($character->gems < $cosmetic->cost_gems) {
                return response()->json(['message' => 'Not enough gems.'], 422);
            }
            if ($cosmetic->cost_gems > 0) {
                $character->decrement('gems', $cosmetic->cost_gems);
                GemLedger::log($character, -$cosmetic->cost_gems, "cosmetic_unlock:{$cosmetic->key}");
            }
        }

        $character->cosmetics()->create(['cosmetic_id' => $cosmetic->id]);

        return response()->json(['character' => $character->fresh()]);
    }

    /** Equips a title/color/banner. Testers can equip anything without owning it first — they can freely
     * preview/switch between every cosmetic in the game. */
    public function equip(Request $request, Cosmetic $cosmetic)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $isTester = $request->user()->isTester();
        if (! $isTester && ! $character->cosmetics()->where('cosmetic_id', $cosmetic->id)->exists()) {
            return response()->json(['message' => 'Unlock this first.'], 422);
        }

        $column = self::ACTIVE_COLUMN[$cosmetic->type];
        $character->update([$column => $cosmetic->id]);

        return response()->json(['character' => $character->fresh(['activeTitle', 'activeColor', 'activeBanner', 'activeIcon'])]);
    }
}
