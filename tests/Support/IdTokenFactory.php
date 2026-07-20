<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * Builds an RS256 keypair + signed id_token + matching JWKS/discovery fakes,
 * so tests never touch a real IdP.
 */
class IdTokenFactory
{
    private const KID = 'test-key-1';

    private array $keyPair;

    public function __construct()
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        $this->keyPair = [
            'private' => $privateKey,
            'public_n' => $details['rsa']['n'],
            'public_e' => $details['rsa']['e'],
        ];
    }

    public function issueToken(array $claims): string
    {
        return JWT::encode($claims, $this->keyPair['private'], 'RS256', self::KID);
    }

    public function jwks(): array
    {
        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => self::KID,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => self::base64urlEncode($this->keyPair['public_n']),
                'e' => self::base64urlEncode($this->keyPair['public_e']),
            ]],
        ];
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function discoveryDocument(): array
    {
        return [
            'issuer' => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/oauth/authorize',
            'token_endpoint' => 'https://idp.test/oauth/token',
            'jwks_uri' => 'https://idp.test/.well-known/jwks.json',
            'end_session_endpoint' => 'https://idp.test/oauth/logout',
            'userinfo_endpoint' => 'https://idp.test/oauth/userinfo',
        ];
    }

    /**
     * Fakes only the discovery endpoint — enough for /auth/login, which never touches
     * the token endpoint. Http::fake() stubs are matched in registration order (first
     * match wins), so callers that also need fakeHealthyIdp() for the callback step
     * must call this instead of fakeHealthyIdp() beforehand, or the token endpoint's
     * first-registered (and possibly stale) stub would shadow the later one.
     */
    public function fakeDiscoveryOnly(): void
    {
        Http::fake([
            'https://idp.test/.well-known/openid-configuration' => Http::response($this->discoveryDocument()),
        ]);
    }

    /**
     * Fakes discovery + jwks + token endpoints for a full happy-path exchange.
     */
    public function fakeHealthyIdp(array $claimsOverride = []): void
    {
        $claims = array_merge([
            'iss' => 'https://idp.test',
            'aud' => 'test-client',
            'sub' => 'user-123',
            'email' => 'user@example.com',
            'name' => 'Example User',
            'iat' => time(),
            'exp' => time() + 300,
        ], $claimsOverride);

        Http::fake([
            'https://idp.test/.well-known/openid-configuration' => Http::response($this->discoveryDocument()),
            'https://idp.test/.well-known/jwks.json' => Http::response($this->jwks()),
            'https://idp.test/oauth/token' => Http::response([
                'id_token' => $this->issueToken($claims),
                'access_token' => 'test-access-token',
            ]),
            'https://idp.test/oauth/userinfo' => Http::response([]),
        ]);
    }

    public function fakeDownIdp(): void
    {
        Http::fake([
            'https://idp.test/.well-known/openid-configuration' => Http::response(null, 500),
        ]);
    }
}
