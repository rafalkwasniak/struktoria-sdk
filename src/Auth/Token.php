<?php

namespace Struktoria\Sdk\Auth;

use DateTimeImmutable;

/**
 * Immutable representation of an issued access token and its lifetime.
 *
 * Replaces the three loose Session keys the old code used
 * (struktoriaToken / struktoriaRefreshToken / struktoriaExpitesAt) with one
 * cohesive object that also knows whether it is still valid.
 */
final class Token
{
    public function __construct(
        private string $accessToken,
        private ?string $refreshToken,
        private DateTimeImmutable $expiresAt,
    ) {
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Has the token reached (or passed) its expiry?
     *
     * @param DateTimeImmutable|null $now Override "now" - handy in tests.
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $now >= $this->expiresAt;
    }
}
