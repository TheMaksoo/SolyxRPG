<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GemLedger;
use App\Models\Mail;
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
            GemLedger::log($character, $data['gems'], 'gm_grant');
        }

        AuditLog::record($request->user()->id, 'gm.player.grant', 'users', $user->id, $data);

        return response()->json(['character' => $character->fresh()]);
    }

    public function mail(Request $request, User $user)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $mail = Mail::create([
            'recipient_user_id' => $user->id,
            'sender_gm_id' => $request->user()->id,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        AuditLog::record($request->user()->id, 'gm.player.mail', 'users', $user->id, $data);

        return response()->json(['mail' => $mail]);
    }

    /** Toggles a persisted ban: sets/clears banned_at and, when banning, revokes active sessions/tokens. */
    public function ban(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot ban your own account.');
        abort_if($user->isGm(), 422, 'Cannot ban another GM/Owner.');

        if ($user->isBanned()) {
            $user->banned_at = null;
            $user->save();

            AuditLog::record($request->user()->id, 'gm.player.unban', 'users', $user->id);

            return response()->json(['message' => "Unbanned {$user->name}.", 'banned' => false]);
        }

        $user->banned_at = now();
        $user->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        AuditLog::record($request->user()->id, 'gm.player.ban', 'users', $user->id);

        return response()->json(['message' => "Banned {$user->name} and revoked active sessions.", 'banned' => true]);
    }
}
