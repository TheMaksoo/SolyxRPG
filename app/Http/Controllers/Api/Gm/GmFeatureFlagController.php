<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FeatureFlag;
use Illuminate\Http\Request;

class GmFeatureFlagController extends Controller
{
    public function index()
    {
        return response()->json(['flags' => FeatureFlag::orderBy('key')->get()]);
    }

    public function update(Request $request, FeatureFlag $flag)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'tester_only' => ['sometimes', 'boolean'],
        ]);
        $flag->update($data);

        AuditLog::record($request->user()->id, 'gm.flag.update', 'feature_flags', $flag->id, $data);

        return response()->json(['flag' => $flag->fresh()]);
    }
}
