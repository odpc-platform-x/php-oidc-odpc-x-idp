<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The one interface a host Laravel app implements to bridge OIDC claims
 * into its own user store (JIT-provisioning, role assignment, etc).
 */
interface AuthUserService
{
    /**
     * Called after a successful token exchange + id_token verification.
     *
     * @param array<string, mixed> $claims Verified OIDC claims (sub, email, name, picture, ...)
     */
    public function onLogin(array $claims): Authenticatable;
}
