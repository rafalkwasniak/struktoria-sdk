<?php

namespace Struktoria\Sdk\Exception;

use RuntimeException;

/**
 * Thrown when an authenticated API call returns a non-2xx status.
 *
 * Carries the HTTP status so callers can react to it - e.g. treat a 5xx as a
 * provider-side outage and retry/back off, rather than as a client bug.
 */
final class ApiException extends RuntimeException
{
    public function __construct(string $message, private int $status = 0)
    {
        parent::__construct($message);
    }

    /**
     * The HTTP status code that triggered this exception (0 if unknown).
     */
    public function status(): int
    {
        return $this->status;
    }
}
