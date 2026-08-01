<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegacyDiscordUser;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialiteController extends Controller
{
    public function redirect(string $provider)
    {
        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider)
    {
        $back = Auth::check() ? '/settings' : '/landing';

        // The user hit "Cancel"/"Deny" on the provider's consent screen — it redirects back with
        // no `code` param at all (often an `error=access_denied` instead). Calling Socialite
        // anyway would try to exchange a nonexistent code for a token and blow up with a raw
        // Guzzle 400, so bail out gracefully here instead.
        if (request()->filled('error') || ! request()->filled('code')) {
            return redirect("{$back}?oauth_error=cancelled");
        }

        try {
            $oauthUser = Socialite::driver($provider)->stateless()->user();
        } catch (Throwable $e) {
            Log::warning("Socialite {$provider} callback failed", ['error' => $e->getMessage()]);

            return redirect("{$back}?oauth_error=failed");
        }

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

            if ($provider === 'discord') {
                LegacyDiscordUser::grantLegendTitleIfMatched($current);
            }
        }

        return redirect("/settings?linked={$provider}");
    }

    private function loginOrRegister(string $provider, $oauthUser, ?SocialAccount $social)
    {
        $user = $social?->user ?: DB::transaction(function () use ($provider, $oauthUser) {
            // No SocialAccount row yet for this provider+ID — but if this email already has a
            // regular (or other-provider) account, attach this identity to it rather than trying
            // to INSERT a second user with the same email, which fails on the unique constraint.
            $email = $oauthUser->getEmail();
            $user = $email ? User::where('email', $email)->first() : null;

            if (! $user) {
                $user = User::create([
                    'name' => $oauthUser->getName() ?: $oauthUser->getNickname() ?: 'Player',
                    'email' => $email ?: Str::uuid().'@solyx.local',
                    'password' => null,
                ]);
                // tos_accepted_at isn't in User's #[Fillable] allow-list — set it directly rather than
                // via create()/update(). The landing page's OAuth buttons carry an explicit "by
                // continuing you agree to..." notice right below them (see LandingPage.vue), so a brand
                // new OAuth signup counts as acceptance the same way the email/password path's checkbox does.
                $user->tos_accepted_at = now();
                $user->save();
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $oauthUser->getId(),
                'discord_id' => $provider === 'discord' ? $oauthUser->getId() : null,
            ]);

            return $user;
        });

        if ($provider === 'discord') {
            LegacyDiscordUser::grantLegendTitleIfMatched($user);
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect($user->character ? '/dashboard' : '/character/create');
    }
}
