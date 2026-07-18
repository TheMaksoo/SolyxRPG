<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Services\SkillService;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    public function __construct(private SkillService $skillService) {}

    /** Every class has its own 3-skill kit — only the active character's base_class skills are returned. */
    public function index(Request $request)
    {
        $character = $request->user()->character;

        $skills = Skill::where('class_scope', $character?->base_class)->orderBy('tier')->get();
        $skills->each(fn (Skill $skill) => $skill->preview_effect = $this->skillService->describe($skill, 1));

        return response()->json(['skills' => $skills]);
    }
}
