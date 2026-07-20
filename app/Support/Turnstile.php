<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

class Turnstile
{
    /**
     * Verifies a Cloudflare Turnstile response token. Returns true (and skips the network call
     * entirely) when no secret key is configured, so local/dev environments without a Cloudflare
     * site set up keep working unchanged.
     */
    public static function verify(?string $token, ?string $ip): bool
    {
        $secret = config('services.turnstile.secret_key');

        if (! $secret) {
            return true;
        }

        if (! $token) {
            return false;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        return (bool) ($response->json('success') ?? false);
    }
}
