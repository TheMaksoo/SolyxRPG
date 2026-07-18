<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassProgression;
use Illuminate\Http\Request;

class ClassProgressionController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $progressions = ClassProgression::where('base_class', $character->base_class)
            ->orderBy('tier')
            ->get();

        return response()->json(['progressions' => $progressions]);
    }
}
