<?php

namespace Struktoria\Sdk\Auth;

use Struktoria\Sdk\Contracts\TokenStore;

/**
 * Trivial TokenStore that keeps the token in a property for the lifetime of
 * this PHP process (i.e. a single request).
 *
 * Ships with the package as a zero-dependency default: enough for tests and
 * for non-Laravel consumers. A Laravel app would instead bind a session- or
 * cache-backed implementation of TokenStore.
 */
final class InMemoryTokenStore implements TokenStore
{
    private ?Token $token = null;

    public function get(): ?Token
    {
        return $this->token;
    }

    public function save(Token $token): void
    {
        $this->token = $token;
    }

    public function clear(): void
    {
        $this->token = null;
    }
}
