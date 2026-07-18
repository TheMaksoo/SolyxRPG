<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $earned = $character->achievements()->get()->keyBy('achievement_id');

        $achievements = Achievement::where('enabled', true)->get()->map(fn (Achievement $a) => [
            'achievement' => $a,
            'earned' => $earned->has($a->id),
            'earned_at' => $earned->get($a->id)?->earned_at,
        ]);

        return response()->json(['achievements' => $achievements]);
    }
}
