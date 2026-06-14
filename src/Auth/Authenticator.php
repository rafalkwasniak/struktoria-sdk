<?php

namespace Struktoria\Sdk\Auth;

use DateTimeImmutable;
use Struktoria\Sdk\Config;
use Struktoria\Sdk\Contracts\TokenStore;
use Struktoria\Sdk\Exception\AuthException;
use Struktoria\Sdk\Http\HttpClient;

/**
 * Owns the login flow and hands out a valid access token on demand.
 *
 * Depends only on the SDK's own pieces - Config (credentials), HttpClient
 * (transport) and the TokenStore *interface* (storage). It never touches a
 * framework, so the same class works in Laravel, plain PHP or a test.
 */
final class Authenticator
{
    /** Refresh this many seconds before the real expiry, to avoid racing it. */
    private const EXPIRY_SKEW_SECONDS = 60;

    /**
     * @param int $defaultTtlSeconds Token lifetime to assume when the access
     *                               token does not carry its own expiry
     *                               (defaults to 6 hours).
     */
    public function __construct(
        private Config $config,
        private HttpClient $http,
        private TokenStore $store,
        private int $defaultTtlSeconds = 21600,
    ) {
    }

    /**
     * Return a currently valid access token, logging in only when the stored
     * one is missing or expired. This is what the request layer will call.
     */
    public function accessToken(): string
    {
        $token = $this->store->get();
        if (null !== $token && ! $token->isExpired()) {
            return $token->accessToken();
        }

        $token = $this->login();
        $this->store->save($token);

        return $token->accessToken();
    }

    /**
     * Perform the token exchange against the platform/login endpoint and return
     * the issued Token. Public so callers can force a fresh login if needed.
     *
     * @throws AuthException on any non-2xx response or a body without a token.
     */
    public function login(): Token
    {
        $response = $this->http->postJson(
            $this->config->authUrl().'/login',
            [
                'login' => $this->config->login(),
                'password' => $this->config->password(),
                'tenantCode' => $this->config->tenantCode(),
                // NOTE: the API field is literally "enviroment" (their spelling).
                // We keep our internal name correct (environment()) but match the
                // wire contract here on purpose.
                'enviroment' => $this->config->environment(),
                'clientAppId' => $this->config->clientAppId(),
                'clientPrivateKey' => $this->config->clientPrivateKey(),
            ],
            [],
            [
                'Accept' => '*/*',
                'AgnetAuth' => 'ApiKey '.$this->config->apiKey(),
                'Authorization' => 'Basic '.base64_encode(
                    $this->config->basicLogin().':'.$this->config->basicPassword()
                ),
            ]
        );

        if (! $response->ok()) {
            throw new AuthException(sprintf(
                'Struktoria login failed with HTTP %d (often a provider-side 5xx, not a client bug). Body: %s',
                $response->status(),
                substr((string) $response->raw(), 0, 300)
            ));
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['accessToken'])) {
            throw new AuthException(
                'Struktoria login succeeded (HTTP '.$response->status().') but returned no accessToken. Body: '
                .substr((string) $response->raw(), 0, 300)
            );
        }

        return new Token(
            $data['accessToken'],
            $data['refreshToken'] ?? null,
            $this->resolveExpiry($data['accessToken']),
        );
    }

    /**
     * Decide when the access token should be considered expired.
     *
     * The token itself is the authoritative source: a JWT carries its own "exp"
     * claim, so we read it and refresh a touch early. If the token is not a
     * parseable JWT, we fall back to the configured default lifetime.
     */
    private function resolveExpiry(string $accessToken): DateTimeImmutable
    {
        $now = new DateTimeImmutable();
        $exp = $this->jwtExpiry($accessToken);

        if (null !== $exp && $exp > $now->getTimestamp()) {
            return $now->setTimestamp($exp - self::EXPIRY_SKEW_SECONDS);
        }

        return $now->modify('+'.$this->defaultTtlSeconds.' seconds');
    }

    /**
     * Read the "exp" (Unix timestamp) claim out of a JWT without verifying its
     * signature - we only need the expiry, and the token came straight from the
     * auth endpoint over TLS.
     *
     * @return int|null The exp timestamp, or null if the token is not a JWT or
     *                  carries no usable exp claim.
     */
    private function jwtExpiry(string $jwt): ?int
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }

        $base64 = strtr($parts[1], '-_', '+/');
        $base64 = str_pad($base64, (int) (ceil(strlen($base64) / 4) * 4), '=');
        $payload = json_decode((string) base64_decode($base64), true);

        if (! is_array($payload) || ! isset($payload['exp']) || ! is_numeric($payload['exp'])) {
            return null;
        }

        return (int) $payload['exp'];
    }
}
