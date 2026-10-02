<p align="center">
  <a href="https://idp.odpcx.com"><img src="https://cdn.odpcx.com/public/idp/idp-logo.webp" alt="ODPCX IdP Link" height="72"></a>
  &nbsp;&nbsp;
  <img src="https://cdn.odpcx.com/public/idp/odpcx-logo.webp" alt="ODPC-X Platform" height="72">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/OpenID_Connect-authorization_code%20%2B%20PKCE-F78C40?logo=openid&logoColor=white" alt="OpenID Connect">
  <img src="https://img.shields.io/badge/JWT-firebase%2Fphp--jwt%20RS256-000000?logo=jsonwebtokens&logoColor=white" alt="firebase/php-jwt">
  <img src="https://img.shields.io/badge/Pest-tested-6E9F18?logo=php&logoColor=white" alt="Pest">
</p>

# odpc-platform-x/php-oidc-odpc-x-idp

Reusable Laravel OIDC (authorization-code + PKCE flow) package against the ODPCX
IdP Link. PHP/Laravel sibling of
[`@odpc-platform-x/nest-oidc-odpc-x-idp`](https://github.com/odpc-platform-x/nest-oidc-odpc-x-idp) —
same route table and contract shape, but built on Laravel's own session guard
instead of a hand-rolled JWT cookie: Laravel already gives you the session
cookie, the `web` guard, and `auth` middleware for free, so this package is
just one config file, one interface, one OIDC client, and one controller.

## Requirements

- **PHP >= 8.2**
- **Laravel 11, 12 or 13** (`illuminate/support`, `illuminate/http`, `illuminate/auth`, `illuminate/routing`)
- `firebase/php-jwt` `^7.0` (installed automatically as a dependency)

## Install

This package is not published to Packagist — install it as a VCS repository.

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:odpc-platform-x/php-oidc-odpc-x-idp.git"
    }
  ]
}
```

```bash
composer config repositories.odpcx-idp vcs git@github.com:odpc-platform-x/php-oidc-odpc-x-idp.git
composer require odpc-platform-x/php-oidc-odpc-x-idp
```

Laravel package auto-discovery registers `OdpcxAuthServiceProvider` automatically.

## What you implement: `AuthUserService`

```php
use Illuminate\Contracts\Auth\Authenticatable;
use OdpcPlatformX\PhpOidcOdpcXIdp\Contracts\AuthUserService;

class MyAuthUserService implements AuthUserService
{
    public function onLogin(array $claims): Authenticatable
    {
        // your own Eloquent upsert / JIT role provisioning / season logic goes here
        return User::updateOrCreate(
            ['idp_sub' => $claims['sub']],
            ['email' => $claims['email'], 'name' => $claims['name']],
        );
    }
}
```

### `$claims` keys

Always present; `null` when the IdP did not release the claim (it depends on
the scopes you request). `/userinfo` wins over the id_token when both carry a key.

| Key | Scope needed |
|-----|--------------|
| `sub`, `id_token` | `openid` |
| `name`, `given_name`, `family_name`, `picture`, `phone_number`, `birthdate`, `address` | `profile` |
| `email`, `email_verified` | `email` |
| `mfa_enabled` | `mfa` |
| `citizen_id` | `cid` |

Bind it in your `AppServiceProvider`:

```php
use OdpcPlatformX\PhpOidcOdpcXIdp\Contracts\AuthUserService;

public function register(): void
{
    $this->app->bind(AuthUserService::class, MyAuthUserService::class);
}
```

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=odpcx-auth-config
```

## Env vars

```dotenv
# Official ODPCX IdP Link (frontend: https://idp.odpcx.com)
ODPCX_OIDC_ISSUER=https://api.idp.odpcx.com
ODPCX_OIDC_CLIENT_ID=...
ODPCX_OIDC_CLIENT_SECRET=...
ODPCX_OIDC_REDIRECT_URI=https://your-app.example.com/auth/callback
ODPCX_OIDC_SCOPES="openid profile email"   # opt-in extras: mfa, cid (see Scopes below)
ODPCX_OIDC_POST_LOGOUT_REDIRECT_URI=https://your-app.example.com

ODPCX_OIDC_GUARD=web
ODPCX_OIDC_DISCOVERY_TTL=3600
ODPCX_OIDC_ROUTES_ENABLED=true
ODPCX_OIDC_ROUTES_PREFIX=auth
```

Discovery document: `https://api.idp.odpcx.com/.well-known/openid-configuration`

## Route table

| Endpoint | Behavior |
|----------|----------|
| `GET {prefix}/login` | Stores PKCE state/nonce/verifier in the Laravel session, redirects (302) to the IdP authorization endpoint. Aborts 503 if discovery is unavailable. |
| `GET {prefix}/callback?code&state&error` | **Success**: verifies state against the session tx, exchanges the code for tokens, verifies the id_token (RS256 vs JWKS + iss/aud/nonce), calls your `AuthUserService::onLogin()`, logs the user in via `Auth::guard()->login()`, regenerates the session, redirects to `intended()`. **`?error`**: redirects to `/` without logging in and flashes `odpcx_error` (see Login errors). **State mismatch / missing tx**: aborts 401. **`onLogin()` throws**: bubbles as a 500. |
| `POST {prefix}/logout` (auth) | Logs out, invalidates the session, regenerates the CSRF token, returns JSON `{ "logoutUrl": "..." }`. The client must navigate to `logoutUrl` itself. |
| `GET {prefix}/me` (auth) | Returns the authenticated user as JSON. Unauthenticated: 401. |

## Scopes

Default is `openid profile email`. Opt in through `ODPCX_OIDC_SCOPES`:

- `mfa` — adds `mfa_enabled`.
- `cid` — adds `citizen_id` (Thai citizen ID). Highest-sensitivity PII: the IdP
  audits every release and the client must be allowed to request it. Only ask
  for it if you need it, and never log or expose it.
- `offline_access` — the IdP supports it, but this package has no refresh flow, so it has no effect here.

## Login errors

If the IdP redirects back with `?error` (e.g. `access_denied`), the package
redirects to `/` and flashes `odpcx_error` to the session:

```php
session('odpcx_error'); // ['error' => 'access_denied', 'error_description' => '...']
```

## Discovery caching — no restart needed on IdP recovery

Discovery and JWKS documents are cached (`Cache::remember`-style) for
`discovery_ttl` seconds, but only on **success**. A failed fetch is never
cached — it aborts with a 503 (`"OIDC provider is not configured or discovery
is unavailable"`) and the very next request retries the fetch from scratch.
If the IdP is temporarily down, the app recovers automatically once it's back,
no restart required.

## Options reference (`config/odpcx-auth.php`)

| Key | Default | Notes |
|-----|---------|-------|
| `issuer` | `https://api.idp.odpcx.com` | ODPCX IdP Link discovery issuer |
| `client_id` / `client_secret` | — | from the IdP |
| `redirect_uri` | — | must match the IdP client's registered redirect |
| `scopes` | `openid profile email` | see Scopes — `mfa`, `cid` are opt-in |
| `post_logout_redirect_uri` | — | where the IdP sends the browser after logout |
| `guard` | `web` | the Laravel auth guard used for `Auth::guard($guard)` |
| `discovery_ttl` | `3600` | seconds; failed fetches are never cached (see above) |
| `routes.enabled` | `true` | set `false` to omit the built-in routes entirely |
| `routes.prefix` | `auth` | e.g. set to `oidc` to mount at `/oidc/*` |
| `routes.middleware` | `['web']` | applied to the whole route group |

## Not included (by design)

- Role/permission guards — layer your own on top of the `auth` middleware.
- Any persistence — `AuthUserService::onLogin()` is where that goes.
- Refresh-token flow — there is no refresh token flow. Once the Laravel
  session expires, the user must log in again via `GET {prefix}/login`.
- A `getMe()` hook — `GET {prefix}/me` returns `Auth::user()` as-is; customize
  via your `Authenticatable` model's serialization (`toArray()` / API resource).

---

<p align="center"><sub>ODPC-X-Platform : สำนักงานป้องกันควบคุมโรคที่ 10 จังหวัดอุบลราชธานี</sub></p>
