<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * A milk collection is always arranged for a time still to come. Applied only to milk jobs:
 * a standard job may be given a time that has already passed.
 */
class FutureMilkCollection implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $at = Carbon::parse($value);
        } catch (Throwable) {
            // The `date` rule reports an unreadable value.
            return;
        }

        if (! $at->isFuture()) {
            $fail('Choose a collection time in the future.');
        }
    }
}
