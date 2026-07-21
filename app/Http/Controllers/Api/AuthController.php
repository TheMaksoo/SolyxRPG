<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', PasswordRule::min(8)->letters()->numbers()->uncompromised()],
            'cf_turnstile_response' => ['nullable', 'string'],
            'tos_accepted' => ['accepted'],
        ])->validate();

        if (! Turnstile::verify($data['cf_turnstile_response'] ?? null, $request->ip())) {
            return response()->json(['message' => 'Bot check failed. Please try again.'], 422);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // tos_accepted_at isn't in User's #[Fillable] allow-list — set it directly rather than via create()/update().
        $user->tos_accepted_at = now();
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->load('character', 'characters', 'socialAccounts')]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($data)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if (Auth::user()->isBanned()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json(['message' => 'This account has been banned.'], 403);
        }

        $request->session()->regenerate();

        return response()->json(['user' => Auth::user()->load('character.attributes_', 'characters', 'socialAccounts')]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    /** Nav-gated feature keys — mirrors what each controller checks via FeatureFlag::gate(). Surfaced here
     * so the sidebar can hide a tab entirely (not just 403 on click) when LIVE is off and the player isn't
     * a tester, or when both LIVE and TESTERS are off for everyone. */
    private const NAV_FLAG_KEYS = [
        'guilds', 'battle_pass', 'gem_store', 'dungeons', 'crafting', 'vip', 'cosmetics',
        'shop', 'skills', 'trade_skills', 'pets', 'pvp', 'party', 'friends', 'leaderboard',
        'daily', 'battle', 'quests', 'world_map', 'inventory',
    ];

    public function me(Request $request)
    {
        $user = $request->user()->load('character.attributes_', 'characters', 'socialAccounts');

        return response()->json([
            'user' => $user,
            // Surfaced here (rather than only under /gm/feature-flags, which players can't reach) so a
            // tester's own Settings toggle can show whether their perks are actually live right now, not
            // just whether they've personally opted in — the GM's global switch is the other half of it.
            'global_tester_mode' => (bool) FeatureFlag::where('key', 'global_tester_mode')->value('enabled'),
            'feature_access' => collect(self::NAV_FLAG_KEYS)->mapWithKeys(
                fn (string $key) => [$key => FeatureFlag::gate($key, $user)]
            ),
        ]);
    }

    /** Lets an already-designated tester flip their own tester perks on/off, without needing a GM to
     * do it — self-serve so they can preview the game as a regular player. Restricted to accounts that
     * already carry the tester designation (is_tester or role=tester) so a plain player can't self-grant.
     * Flips `tester_mode_disabled`, NOT the designation itself — toggling off never revokes is_tester, so
     * the player can always flip it back on themselves rather than needing a GM to re-grant it. */
    public function toggleTesterMode(Request $request)
    {
        $user = $request->user();
        abort_unless($user->is_tester || $user->role === 'tester', 403, 'Not a tester account.');

        $user->tester_mode_disabled = ! $user->tester_mode_disabled;
        $user->save();

        return response()->json(['is_tester' => $user->is_tester, 'tester_mode_disabled' => $user->tester_mode_disabled]);
    }

    /** Player-facing display/UX preferences (chat highlighting, battle log density, etc) — merged into
     * the existing JSON blob rather than replaced, so toggling one preference never clobbers others.
     * Uses direct property assignment + save() rather than update()/create() because User's #[Fillable]
     * attribute only allows mass-assigning name/email/password — bypassing that is the established
     * pattern in this codebase for any other user column. */
    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'highlight_mentions' => ['sometimes', 'boolean'],
            'compact_battle_log' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $user->preferences = array_merge($user->preferences ?? [], $data);
        $user->save();

        return response()->json(['preferences' => $user->preferences]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'A password reset link has been sent to that email.']);
        }

        // Deliberately vague on failure so this endpoint can't be used to enumerate registered emails.
        return response()->json(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8)->letters()->numbers()->uncompromised(), 'confirmed'],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        });

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset. You can now log in.']);
        }

        return response()->json(['message' => __($status)], 422);
    }
}
