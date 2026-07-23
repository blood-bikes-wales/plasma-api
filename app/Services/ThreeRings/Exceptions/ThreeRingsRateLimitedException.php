<?php

namespace App\Services\ThreeRings\Exceptions;

/**
 * Thrown when the local Three Rings request budget is exhausted and no
 * sufficiently recent cached copy of the requested data exists.
 */
class ThreeRingsRateLimitedException extends ThreeRingsException
{
    private int $retryAfterSeconds = 0;

    public static function retryAfter(int $seconds): self
    {
        $exception = new self("Three Rings request budget exhausted; retry in {$seconds} seconds.");
        $exception->retryAfterSeconds = $seconds;

        return $exception;
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
