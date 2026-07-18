<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GmPlayerController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');

        $users = User::with('character')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json(['players' => $users]);
    }

    public function grant(Request $request, User $user)
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'exists:items,id'],
            'gold' => ['nullable', 'integer'],
            'gems' => ['nullable', 'integer'],
        ]);

        $character = $user->character;
        abort_unless($character, 404, 'Player has no character.');

        if (! empty($data['item_id'])) {
            $character->inventory()->create(['item_id' => $data['item_id'], 'qty' => 1]);
        }
        if (! empty($data['gold'])) {
            $character->increment('gold', $data['gold']);
        }
        if (! empty($data['gems'])) {
            $character->increment('gems', $data['gems']);
        }

        AuditLog::record($request->user()->id, 'gm.player.grant', 'users', $user->id, $data);

        return response()->json(['character' => $character->fresh()]);
    }

    /** A dedicated banned_at column + login gate would be the real implementation; this revokes active sessions/tokens as the lightweight version. */
    public function ban(Request $request, User $user)
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        AuditLog::record($request->user()->id, 'gm.player.ban', 'users', $user->id);

        return response()->json(['message' => "Revoked {$user->name}'s active sessions."]);
    }
}
