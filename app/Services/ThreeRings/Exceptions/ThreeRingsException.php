<?php

namespace App\Services\ThreeRings\Exceptions;

use RuntimeException;

/**
 * Base exception for all Three Rings client failures.
 *
 * Callers should catch this type to degrade gracefully when Three Rings
 * data cannot be read (BR-008).
 */
abstract class ThreeRingsException extends RuntimeException
{
    //
}
