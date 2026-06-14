<?php

namespace Struktoria\Sdk;

/**
 * Immutable connection configuration for the Struktoria API.
 *
 * Holds only static credentials and endpoints. The runtime access token is
 * NOT part of the config - it lives in a TokenStore, because it changes over
 * time and its storage (session, cache, ...) is the host application's concern.
 *
 * All secrets are injected from the outside (the app's .env), never hardcoded
 * inside the package. Constructor promotion + typed, required parameters mean
 * PHP itself enforces that nothing is missing - no manual validation needed.
 */
final class Config
{
    public function __construct(
        private string $baseUrl,
        private string $basicLogin,
        private string $basicPassword,
        private string $login,
        private string $password,
        private string $tenantCode,
        private string $environment,
        private string $clientAppId,
        private string $clientPrivateKey,
        private string $apiKey,
    ) {
        // Normalise away a trailing slash so module URLs never double up.
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Build a full module endpoint URL, e.g. moduleUrl('documents') =>
     * https://your-struktoria-host/v1/documents/api
     *
     * @param string $module One of: platform, documents, rag.
     */
    public function moduleUrl(string $module): string
    {
        return $this->baseUrl.'/'.trim($module, '/').'/api';
    }

    public function authUrl(): string
    {
        return $this->moduleUrl('platform');
    }

    public function documentsUrl(): string
    {
        return $this->moduleUrl('documents');
    }

    public function ragUrl(): string
    {
        return $this->moduleUrl('rag');
    }

    public function basicLogin(): string
    {
        return $this->basicLogin;
    }

    public function basicPassword(): string
    {
        return $this->basicPassword;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function tenantCode(): string
    {
        return $this->tenantCode;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function clientAppId(): string
    {
        return $this->clientAppId;
    }

    public function clientPrivateKey(): string
    {
        return $this->clientPrivateKey;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }
}
