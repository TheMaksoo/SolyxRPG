<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    public function redirect(string $provider)
    {
        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider)
    {
        $oauthUser = Socialite::driver($provider)->stateless()->user();

        $social = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $oauthUser->getId())
            ->first();

        // Already logged in (e.g. hit "Link" from Settings) — attach this identity to the CURRENT
        // account instead of the login/register flow below, which would otherwise log the browser
        // into a different (new or pre-existing) account entirely.
        if (Auth::check()) {
            return $this->linkToCurrentUser($provider, $oauthUser->getId(), $social);
        }

        return $this->loginOrRegister($provider, $oauthUser, $social);
    }

    private function linkToCurrentUser(string $provider, string $providerUserId, ?SocialAccount $social)
    {
        $current = Auth::user();

        if ($social && $social->user_id !== $current->id) {
            return redirect('/settings?link_error=already_linked_elsewhere');
        }

        if (! $social) {
            SocialAccount::create([
                'user_id' => $current->id,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'discord_id' => $provider === 'discord' ? $providerUserId : null,
            ]);
        }

        return redirect("/settings?linked={$provider}");
    }

    private function loginOrRegister(string $provider, $oauthUser, ?SocialAccount $social)
    {
        $user = $social?->user ?: DB::transaction(function () use ($provider, $oauthUser) {
            $user = User::create([
                'name' => $oauthUser->getName() ?: $oauthUser->getNickname() ?: 'Player',
                'email' => $oauthUser->getEmail() ?: Str::uuid().'@solyx.local',
                'password' => null,
            ]);

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $oauthUser->getId(),
                'discord_id' => $provider === 'discord' ? $oauthUser->getId() : null,
            ]);

            return $user;
        });

        Auth::login($user);
        request()->session()->regenerate();

        return redirect($user->character ? '/dashboard' : '/character/create');
    }
}
