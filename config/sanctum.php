<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired, measured from the token's created_at. It is an
    | ABSOLUTE lifetime backstop.
    |
    | NOTE ON COMBINED SEMANTICS (verified against laravel/sanctum v4.3.2
    | Guard::isValidAccessToken, lines 121-130): the stock comment above that
    | says this "overrides" the per-token expires_at is INACCURATE for this
    | version. The global expiration and each token's own expires_at are ANDed
    | -- BOTH must pass for a token to authenticate:
    |     (! expiration || created_at > now()->subMinutes(expiration))
    |         && (! expires_at || ! expires_at->isPast())
    |
    | So this global value does NOT disturb the SLIDING idle window we already
    | enforce per-token: each token gets expires_at = now + session.lifetime at
    | login (App\Http\Controllers\Api\V1\AuthController) and that expires_at is
    | pushed forward on every authenticated request by the SlideTokenExpiration
    | middleware. The idle window (8h) still governs normal expiry and still
    | slides untouched.
    |
    | What this adds is a hard, non-sliding cap from token creation. Previously
    | NULL meant an actively-used (or scripted keep-alive) token never expired
    | at all (see AUDIT-02 finding 2.3). We now default this to 30 days
    | (SANCTUM_EXPIRATION=43200 minutes) so a stolen-but-kept-alive token dies
    | at most 30 days after it was issued, while honest users still see the same
    | 8h sliding idle behaviour. Set SANCTUM_EXPIRATION=0 (or empty) to restore
    | the old never-expire behaviour. See docs/session-expiry-policy.md and
    | docs/deploy/cpanel-queue-scheduler-and-token-ttl.md.
    |
    */

    'expiration' => (int) env('SANCTUM_EXPIRATION', 43200),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
