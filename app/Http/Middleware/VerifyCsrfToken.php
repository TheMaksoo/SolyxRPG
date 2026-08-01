<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // OAuth flows don't carry CSRF tokens — the redirect happens from the landing page
        // before any session is established, and callbacks come from external providers
        // (Discord, Google) that don't know our token. The OAuth code/state validation
        // provides security instead. Both routes need to be excluded to prevent "token
        // mismatch" errors during the OAuth flow.
        'api/auth/*/redirect',
        'api/auth/*/callback',
    ];
}
