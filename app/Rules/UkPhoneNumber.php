<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A UK phone number as a controller would type it.
 *
 * Spaces, hyphens and round brackets are ignored and a leading +44 counts as 0.
 * What remains must be 10 or 11 digits starting with 0. This accepts landlines
 * (029 2074 7747) and mobiles (07700 900101) but not international numbers.
 *
 * The SPA applies the same rule in `ukPhoneSchema` (app/lib/validation.ts), so
 * keep the two in step. The value is only checked here, never rewritten.
 */
class UkPhoneNumber implements ValidationRule
{
    public const MESSAGE = 'Enter a valid UK phone number, for example 029 2074 7747';

    public static function passes(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $number = preg_replace('/[ \-()]/', '', trim($value));
        if ($number === null) {
            return false;
        }

        if (str_starts_with($number, '+44')) {
            $number = '0'.substr($number, 3);
        }

        return preg_match('/^0\d{9,10}\z/', $number) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes($value)) {
            $fail(self::MESSAGE);
        }
    }
}
