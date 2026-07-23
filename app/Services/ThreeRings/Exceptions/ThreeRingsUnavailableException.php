<?php

namespace App\Services\ThreeRings\Exceptions;

use Throwable;

/**
 * Thrown when Three Rings is unreachable or returning server errors and no
 * sufficiently recent cached copy of the requested data exists.
 */
class ThreeRingsUnavailableException extends ThreeRingsException
{
    public static function forPath(string $path, ?Throwable $previous = null): self
    {
        return new self("Three Rings is unavailable and no cached data exists for [{$path}].", previous: $previous);
    }
}
