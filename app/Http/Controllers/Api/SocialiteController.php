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
        Log::info("OAuth {$provider} redirect initiated", [
            'provider' => $provider,
            'is_authenticated' => Auth::check(),
            'user_id' => Auth::id(),
        ]);

        try {
            $driver = Socialite::driver($provider);

            // Both providers require explicit scopes for email and profile access.
            // Without these, the OAuth response may not include the user's email,
            // which is needed for account creation and linking.
            if ($provider === 'google') {
                $driver->scopes(['email', 'profile']);
            } elseif ($provider === 'discord') {
                // Discord requires identify (basic profile) and email scopes
                $driver->scopes(['identify', 'email']);
            }

            // CSRF protection via HMAC state cookie rather than session state.
            // Session-based state is unreliable across the OAuth redirect chain (the provider's
            // cross-domain redirect changes the session context), causing the standard stateful
            // Socialite flow to intermittently reject valid callbacks with InvalidStateException.
            // Instead: generate a random nonce, store it in a short-lived SameSite=Lax cookie,
            // and pass HMAC(nonce, app_key) as the OAuth state parameter. The callback validates
            // the HMAC — an attacker cannot forge it without the application key.
            $nonce = Str::random(40);
            $state = hash_hmac('sha256', $nonce, config('app.key'));

            return $driver
                ->stateless()
                ->with(['state' => $state])
                ->redirect()
                ->withCookie(cookie('oauth_nonce', $nonce, 5, '/', null, request()->isSecure(), true, false, 'lax'));
        } catch (Throwable $e) {
            Log::error("OAuth {$provider} redirect failed", [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            $back = Auth::check() ? '/settings' : '/landing';
            return redirect("{$back}?oauth_error=failed");
        }
    }

    public function callback(string $provider)
    {
        $back = Auth::check() ? '/settings' : '/landing';

        // The user hit "Cancel"/"Deny" on the provider's consent screen — it redirects back with
        // no `code` param at all (often an `error=access_denied` instead). Calling Socialite
        // anyway would try to exchange a nonexistent code for a token and blow up with a raw
        // Guzzle 400, so bail out gracefully here instead.
        if (request()->filled('error') || ! request()->filled('code')) {
            Log::info("OAuth {$provider} callback: user cancelled or denied", [
                'error' => request('error'),
                'has_code' => request()->filled('code'),
                'query_keys' => array_keys(request()->query()),
            ]);
            return redirect("{$back}?oauth_error=cancelled");
        }

        try {
            // Validate the HMAC state from the cookie before exchanging the code.
            // This is our CSRF guard: the nonce was set by us during redirect() and cannot
            // be forged by an attacker without the application key.
            $nonce = request()->cookie('oauth_nonce');
            $actualState = request()->input('state');
            if (! $nonce || ! $actualState || ! hash_equals(hash_hmac('sha256', $nonce, config('app.key')), $actualState)) {
                Log::warning("OAuth {$provider} state validation failed (CSRF check)", [
                    'provider' => $provider,
                    'has_nonce_cookie' => ! empty($nonce),
                    'has_state_param' => ! empty($actualState),
                ]);
                return redirect("{$back}?oauth_error=failed");
            }

            $oauthUser = Socialite::driver($provider)->stateless()->user();
        } catch (Throwable $e) {
            Log::warning("Socialite {$provider} callback failed", [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'has_code' => request()->filled('code'),
                'has_state' => request()->filled('state'),
            ]);

            return redirect("{$back}?oauth_error=failed");
        }

        // Log successful OAuth user retrieval for diagnostics
        Log::info("OAuth {$provider} user retrieved successfully", [
            'provider' => $provider,
            'provider_user_id' => $oauthUser->getId(),
            'has_email' => !empty($oauthUser->getEmail()),
            'email_domain' => $oauthUser->getEmail() ? explode('@', $oauthUser->getEmail())[1] ?? 'unknown' : null,
        ]);

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
            Log::warning("OAuth {$provider} link attempt failed: already linked to different account", [
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'current_user_id' => $current->id,
                'existing_link_user_id' => $social->user_id,
            ]);
            return redirect('/settings?link_error=already_linked_elsewhere');
        }

        if (! $social) {
            SocialAccount::create([
                'user_id' => $current->id,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'discord_id' => $provider === 'discord' ? $providerUserId : null,
            ]);

            Log::info("OAuth {$provider} linked successfully to existing user", [
                'provider' => $provider,
                'user_id' => $current->id,
                'provider_user_id' => $providerUserId,
            ]);

            if ($provider === 'discord') {
                LegacyDiscordUser::grantLegendTitleIfMatched($current);
            }
        }

        return redirect("/settings?linked={$provider}");
    }

    /** The provider's email, but only if it actually confirms it's verified — Discord in particular lets
     * an account carry an email that was edited but never confirmed, which still comes back from the
     * `identify email` scope. Google's OAuth email is always verified in practice, but the raw flag is
     * checked anyway rather than assumed. */
    private function verifiedEmail(string $provider, $oauthUser): ?string
    {
        $email = $oauthUser->getEmail();
        if (! $email) {
            return null;
        }

        $raw = $oauthUser->getRaw();
        $verified = match ($provider) {
            'discord' => (bool) ($raw['verified'] ?? false),
            'google' => (bool) ($raw['email_verified'] ?? false),
            default => true,
        };

        return $verified ? $email : null;
    }

    private function loginOrRegister(string $provider, $oauthUser, ?SocialAccount $social)
    {
        $user = $social?->user ?: DB::transaction(function () use ($provider, $oauthUser) {
            // No SocialAccount row yet for this provider+ID — but if this email already has a
            // regular (or other-provider) account, attach this identity to it rather than trying
            // to INSERT a second user with the same email, which fails on the unique constraint.
            // Only trust the email for that lookup if the provider confirms it's actually verified —
            // Discord in particular lets an account carry an email that was changed but never
            // confirmed, which would otherwise let an attacker "become" any existing player by just
            // typing their email into their own Discord account and signing in with it.
            $email = $this->verifiedEmail($provider, $oauthUser);
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

                Log::info("New user created via OAuth {$provider}", [
                    'provider' => $provider,
                    'user_id' => $user->id,
                    'has_verified_email' => !empty($email),
                ]);
            } else {
                Log::info("Existing user found by verified email, linking OAuth {$provider}", [
                    'provider' => $provider,
                    'user_id' => $user->id,
                    'email_domain' => $email ? explode('@', $email)[1] ?? 'unknown' : null,
                ]);
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

        Log::info("User logged in via OAuth {$provider}", [
            'provider' => $provider,
            'user_id' => $user->id,
            'has_character' => !empty($user->character),
        ]);

        return redirect($user->character ? '/dashboard' : '/character/create');
    }
}
