<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ])->validate();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

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

    public function me(Request $request)
    {
        $user = $request->user()->load('character.attributes_', 'characters', 'socialAccounts');

        return response()->json(['user' => $user]);
    }

    /** Lets an already-designated tester flip their own tester perks on/off, without needing a GM to
     * do it — self-serve so they can preview the game as a regular player. Restricted to accounts that
     * already carry the tester designation (is_tester or role=tester) so a plain player can't self-grant. */
    public function toggleTesterMode(Request $request)
    {
        $user = $request->user();
        abort_unless($user->is_tester || $user->role === 'tester', 403, 'Not a tester account.');

        $user->is_tester = ! $user->is_tester;
        $user->save();

        return response()->json(['is_tester' => $user->is_tester]);
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
