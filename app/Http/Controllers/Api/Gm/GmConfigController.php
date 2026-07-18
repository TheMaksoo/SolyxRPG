<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GameConfig;
use Illuminate\Http\Request;

class GmConfigController extends Controller
{
    public function index()
    {
        return response()->json(['config' => GameConfig::orderBy('key')->get()]);
    }

    public function update(Request $request, string $key)
    {
        $data = $request->validate(['value' => ['required']]);
        $row = GameConfig::updateOrCreate(['key' => $key], ['value' => (string) $data['value']]);

        AuditLog::record($request->user()->id, 'gm.config.update', 'game_config', $row->id, $data);

        return response()->json(['config' => $row]);
    }
}
