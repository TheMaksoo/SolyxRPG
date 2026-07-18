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
        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider)
    {
        $oauthUser = Socialite::driver($provider)->user();

        $social = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $oauthUser->getId())
            ->first();

        $user = $social?->user;

        if (! $user) {
            $user = DB::transaction(function () use ($provider, $oauthUser) {
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
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect(($user->character ? '/dashboard' : '/character/create'));
    }
}
