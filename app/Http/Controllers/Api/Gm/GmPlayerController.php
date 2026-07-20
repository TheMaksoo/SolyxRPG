<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Battle;
use App\Models\GemLedger;
use App\Models\Mail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    /** Support-tool edit: user account fields (role/VIP/tester/ban reason) plus that user's character stats. */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in(['player', 'tester', 'gm', 'owner'])],
            'is_tester' => ['nullable', 'boolean'],
            'vip_tier' => ['nullable', Rule::in(['none', 'bronze', 'gold', 'diamond'])],
            'vip_expires_at' => ['nullable', 'date'],
            'banned_reason' => ['nullable', 'string', 'max:500'],
            'level' => ['nullable', 'integer', 'min:0'],
            'xp' => ['nullable', 'integer', 'min:0'],
            'gold' => ['nullable', 'integer', 'min:0'],
            'gems' => ['nullable', 'integer', 'min:0'],
            'hp' => ['nullable', 'integer', 'min:0'],
            'hp_max' => ['nullable', 'integer', 'min:0'],
            'mana' => ['nullable', 'integer', 'min:0'],
            'mana_max' => ['nullable', 'integer', 'min:0'],
            'energy' => ['nullable', 'integer', 'min:0'],
            'energy_max' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('role', $data) && $data['role'] === 'owner' && $request->user()->role !== 'owner') {
            abort(403, 'Only an Owner can promote another account to Owner.');
        }

        if (array_key_exists('role', $data)) {
            $user->role = $data['role'];
        }
        if (array_key_exists('is_tester', $data)) {
            $user->is_tester = $data['is_tester'];
        }
        if (array_key_exists('vip_tier', $data)) {
            $user->vip_tier = $data['vip_tier'];
        }
        if (array_key_exists('vip_expires_at', $data)) {
            $user->vip_expires_at = $data['vip_expires_at'];
        }
        if (array_key_exists('banned_reason', $data)) {
            $user->banned_reason = $data['banned_reason'];
        }
        $user->save();

        $characterFields = array_intersect_key($data, array_flip([
            'level', 'xp', 'gold', 'gems', 'hp', 'hp_max', 'mana', 'mana_max', 'energy', 'energy_max',
        ]));
        if ($characterFields && $user->character) {
            $user->character->update($characterFields);
        }

        AuditLog::record($request->user()->id, 'gm.player.update', 'users', $user->id, $data);

        return response()->json(['user' => $user->fresh('character')]);
    }

    /** Un-sticks a character stuck in an active battle or hung auto-battle/auto-gather session. */
    public function clearStuckState(Request $request, User $user)
    {
        $character = $user->character;
        abort_unless($character, 404, 'Player has no character.');

        Battle::where('character_id', $character->id)->where('status', 'active')->update(['status' => 'lost']);

        $character->auto_battle_expires_at = null;
        $character->auto_battle_paused_at = null;
        $character->auto_gather_expires_at = null;
        $character->auto_gather_last_tick_at = null;
        $character->save();

        AuditLog::record($request->user()->id, 'gm.player.clear_stuck_state', 'users', $user->id);

        return response()->json(['message' => "Cleared stuck state for {$user->name}.", 'character' => $character->fresh()]);
    }
}
