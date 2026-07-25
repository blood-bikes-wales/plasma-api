<?php

namespace App\Services\ThreeRings\Exceptions;

use Throwable;

/**
 * Thrown when Three Rings rejects a request (4xx), typically due to a
 * revoked or under-privileged API key or invalid parameters.
 *
 * Never masked by stale cache: serving cached data here would hide a
 * misconfiguration that needs fixing.
 */
class ThreeRingsRequestException extends ThreeRingsException
{
    public static function forPath(string $path, int $status, ?Throwable $previous = null): self
    {
        return new self("Three Rings rejected the request for [{$path}] with status {$status}.", previous: $previous);
    }
}
