<?php

namespace Struktoria\Sdk\Exception;

use RuntimeException;

/**
 * Thrown when the HTTP transport itself fails (connection refused, DNS error,
 * timeout, ...) - i.e. when we never got a usable HTTP response at all.
 *
 * A non-2xx HTTP status is NOT a transport error: the request reached the
 * server and came back, so that is surfaced via Response::status() instead.
 */
final class TransportException extends RuntimeException
{
}
