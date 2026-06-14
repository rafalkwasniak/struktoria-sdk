<?php

namespace Struktoria\Sdk\Http;

/**
 * A thin, immutable wrapper around a raw HTTP response.
 *
 * Keeps the status code (which the old code threw away) next to the body, so
 * callers can tell a real 200 payload apart from a 401/500 error page.
 */
final class Response
{
    public function __construct(
        private int $status,
        private ?string $body,
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * True for any 2xx status.
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The raw response body as returned by the server (may be null/empty).
     */
    public function raw(): ?string
    {
        return $this->body;
    }

    /**
     * The body decoded as a JSON associative array, or null when the body is
     * empty or not valid JSON.
     */
    public function json(): mixed
    {
        if (null === $this->body || '' === $this->body) {
            return null;
        }

        return json_decode($this->body, true);
    }
}
