<?php

namespace Struktoria\Sdk\Contracts;

use Struktoria\Sdk\Auth\Token;

/**
 * Where the SDK keeps the current access token between requests.
 *
 * This is the inversion-of-dependency seam: the SDK defines WHAT it needs
 * (read / write / forget a token) but not HOW it is stored. The host app
 * supplies the HOW - e.g. a Laravel session- or cache-backed implementation -
 * so the SDK itself never depends on any framework.
 */
interface TokenStore
{
    /**
     * The currently stored token, or null if none has been saved yet.
     */
    public function get(): ?Token;

    /**
     * Persist a freshly issued token.
     */
    public function save(Token $token): void;

    /**
     * Forget the stored token (e.g. after a logout or a 401).
     */
    public function clear(): void;
}
