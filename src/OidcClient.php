<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OidcClient
{
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Fetches (and caches) the OIDC discovery document.
     * Cache::remember only wraps the successful result — a failed fetch is
     * never cached, so the very next request retries automatically with no
     * app restart needed once the IdP recovers.
     */
    public function discovery(): array
    {
        $cached = Cache::get('odpcx-auth:discovery');
        if ($cached !== null) {
            return $cached;
        }

        $response = Http::get(rtrim($this->config['issuer'], '/') . '/.well-known/openid-configuration');

        if (! $response->successful()) {
            $this->abortJson(503, 'OIDC provider is not configured or discovery is unavailable');
        }

        $document = $response->json();
        Cache::put('odpcx-auth:discovery', $document, $this->config['discovery_ttl']);

        return $document;
    }

    public function jwks(): array
    {
        $cached = Cache::get('odpcx-auth:jwks');
        if ($cached !== null) {
            return $cached;
        }

        $jwksUri = $this->discovery()['jwks_uri'] ?? null;
        $response = $jwksUri ? Http::get($jwksUri) : null;

        if (! $jwksUri || ! $response->successful()) {
            $this->abortJson(503, 'OIDC provider is not configured or discovery is unavailable');
        }

        $jwks = $response->json();
        Cache::put('odpcx-auth:jwks', $jwks, $this->config['discovery_ttl']);

        return $jwks;
    }

    /**
     * Builds the authorization URL and returns it plus the tx (state/nonce/verifier)
     * to be stored in the caller's session.
     */
    public function buildAuthorizeUrl(): array
    {
        $document = $this->discovery();

        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => $this->config['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        $url = $document['authorization_endpoint'] . '?' . $query;

        return [
            'url' => $url,
            'tx' => [
                'state' => $state,
                'nonce' => $nonce,
                'code_verifier' => $codeVerifier,
            ],
        ];
    }

    /**
     * Exchanges the authorization code for tokens, verifies the id_token
     * (RS256 against JWKS, iss/aud/nonce), and returns normalized claims.
     *
     * @return array{sub: string, email: ?string, name: ?string, picture: ?string, id_token: string}
     */
    public function exchangeCode(string $code, array $tx): array
    {
        $document = $this->discovery();

        $response = Http::asForm()->post($document['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code_verifier' => $tx['code_verifier'],
        ]);

        if (! $response->successful()) {
            $this->abortJson(401, 'Token exchange failed');
        }

        $tokens = $response->json();
        $idToken = $tokens['id_token'] ?? null;

        if (! $idToken) {
            $this->abortJson(401, 'Missing id_token');
        }

        $claims = $this->verifyIdToken($idToken, $tx['nonce']);

        $userinfo = [];
        if (! empty($tokens['access_token']) && ! empty($document['userinfo_endpoint'])) {
            $userinfoResponse = Http::withToken($tokens['access_token'])->get($document['userinfo_endpoint']);
            if ($userinfoResponse->successful()) {
                $userinfo = $userinfoResponse->json();
            }
        }

        return [
            'sub' => $claims['sub'],
            'email' => $userinfo['email'] ?? $claims['email'] ?? null,
            'name' => $userinfo['name'] ?? $claims['name'] ?? null,
            'picture' => $userinfo['picture'] ?? $claims['picture'] ?? null,
            'id_token' => $idToken,
        ];
    }

    private function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        $jwks = $this->jwks();
        $keys = JWK::parseKeySet($jwks);

        try {
            $decoded = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            $this->abortJson(401, 'Invalid id_token: ' . $e->getMessage());
        }

        if (($decoded['iss'] ?? null) !== $this->discovery()['issuer']) {
            $this->abortJson(401, 'id_token issuer mismatch');
        }

        $aud = $decoded['aud'] ?? null;
        $audMatches = $aud === $this->config['client_id']
            || (is_array($aud) && in_array($this->config['client_id'], $aud, true));

        if (! $audMatches) {
            $this->abortJson(401, 'id_token audience mismatch');
        }

        if (($decoded['nonce'] ?? null) !== $expectedNonce) {
            $this->abortJson(401, 'id_token nonce mismatch');
        }

        return $decoded;
    }

    /**
     * Aborts with a JSON body so the message survives — Laravel's default HTML
     * error rendering strips HttpException messages regardless of debug mode,
     * and API/SPA consumers of this package never negotiate HTML anyway.
     */
    private function abortJson(int $code, string $message): never
    {
        abort(response()->json(['message' => $message], $code));
    }

    public function buildLogoutUrl(?string $idTokenHint = null): string
    {
        $document = $this->discovery();
        $endSessionEndpoint = $document['end_session_endpoint'] ?? null;

        if (! $endSessionEndpoint) {
            throw new RuntimeException('OIDC provider does not advertise an end_session_endpoint');
        }

        $params = ['post_logout_redirect_uri' => $this->config['post_logout_redirect_uri']];
        if ($idTokenHint) {
            $params['id_token_hint'] = $idTokenHint;
        }

        return $endSessionEndpoint . '?' . http_build_query($params);
    }
}
