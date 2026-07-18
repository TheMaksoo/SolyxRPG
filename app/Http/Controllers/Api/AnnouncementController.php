<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;

class AnnouncementController extends Controller
{
    public function index()
    {
        return response()->json([
            'announcements' => Announcement::with('gm:id,name')->latest('created_at')->limit(10)->get(),
        ]);
    }
}
