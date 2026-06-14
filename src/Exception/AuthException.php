<?php

namespace Struktoria\Sdk\Exception;

use RuntimeException;

/**
 * Thrown when authentication does not succeed: a non-2xx login response
 * (including the provider's frequent 5xx during development) or a 2xx body
 * that carries no accessToken.
 *
 * Distinct from TransportException on purpose: here the server DID answer, so
 * the message carries the HTTP status - making it obvious when a failure is
 * "their 500", not our connectivity.
 */
final class AuthException extends RuntimeException
{
}
