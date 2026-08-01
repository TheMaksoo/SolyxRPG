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
        // OAuth callbacks don't carry CSRF tokens — they come from external providers
        // (Discord, Google) that don't know our token. The OAuth code/state validation
        // provides security instead. Excluding just the callback route while keeping
        // CSRF on the redirect route (which does have the token) prevents "token mismatch"
        // errors when the provider sends users back to our site.
        'api/auth/*/callback',
    ];
}
