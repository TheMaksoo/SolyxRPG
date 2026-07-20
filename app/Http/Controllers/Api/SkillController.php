<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\Skill;
use App\Services\SkillService;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    public function __construct(private SkillService $skillService) {}

    /** Every class has its own skill kit (3 for most, plus Mage's standalone Healing Light) — only the
     * active character's base_class skills are returned. */
    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('skills', $request->user()), 403, 'Skills are not currently available.');

        $character = $request->user()->character;

        $skills = Skill::where('class_scope', $character?->base_class)->orderBy('tier')->get();
        $skills->each(fn (Skill $skill) => $skill->preview_effect = $this->skillService->describe($skill, 1));

        return response()->json(['skills' => $skills]);
    }
}
