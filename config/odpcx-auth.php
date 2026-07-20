<?php

return [
    // ODPCX IdP Link discovery issuer (https://api.idp.odpcx.com/.well-known/openid-configuration)
    'issuer' => env('ODPCX_OIDC_ISSUER', 'https://api.idp.odpcx.com'),

    'client_id' => env('ODPCX_OIDC_CLIENT_ID'),
    'client_secret' => env('ODPCX_OIDC_CLIENT_SECRET'),
    'redirect_uri' => env('ODPCX_OIDC_REDIRECT_URI'),
    'scopes' => env('ODPCX_OIDC_SCOPES', 'openid profile email'),
    'post_logout_redirect_uri' => env('ODPCX_OIDC_POST_LOGOUT_REDIRECT_URI'),

    // Laravel auth guard used for Auth::guard($guard)->login($user)
    'guard' => env('ODPCX_OIDC_GUARD', 'web'),

    // Seconds discovery document + JWKS are cached — failed fetches are never cached (see OidcClient).
    'discovery_ttl' => env('ODPCX_OIDC_DISCOVERY_TTL', 3600),

    'routes' => [
        'enabled' => env('ODPCX_OIDC_ROUTES_ENABLED', true),
        'prefix' => env('ODPCX_OIDC_ROUTES_PREFIX', 'auth'),
        'middleware' => ['web'],
    ],
];
